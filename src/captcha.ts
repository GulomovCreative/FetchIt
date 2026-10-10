// The browser side of the external captchas (FetchItCaptcha on the server).
// Each adapter puts the provider's answer into the form data before a
// submission and resets the widget after it: an answer is good for one check.
// When there is no answer to send (the script did not load, the visitor
// closed the check, the widget failed) answer() rejects with a CaptchaError
// instead of sending a form the server would refuse.

export type CaptchaConfig = FetchItCaptchaConfig

export interface Captcha {
  // Put the answer into the form data, or reject with a CaptchaError.
  answer (formData: FormData): Promise<void>;
  // Get ready for the next answer; never throws.
  reset (): void;
  // The form is gone: remove the widget, render none later; never throws.
  destroy (): void;
}

export class CaptchaError extends Error {
  override name = 'CaptchaError'
}

// The fields the providers' answers go in, a widget's own included: an
// answer is new for each submission, so it is no part of its data.
export const captchaFields = ['cf-turnstile-response', 'g-recaptcha-response', 'smart-token']

// The reCAPTCHA v3 action the server expects (FetchItCaptcha::RECAPTCHA_ACTION).
const RECAPTCHA_ACTION = 'fetchit'

// How long to wait for the script of the provider.
const SCRIPT_WAIT = 10_000
// How long to wait for an answer: an interactive check takes the visitor a while.
const ANSWER_WAIT = 30_000
// SmartCaptcha may show a puzzle; closing it rejects at once.
const CHALLENGE_WAIT = 180_000

/**
 * Wait until read() gives something, such as the global of the provider's
 * script, checking every 100 ms; undefined after `ms`. What read() throws
 * ends the wait, and so does null: a destroyed captcha waits for nothing.
 */
async function waitFor<T> (read: () => T | undefined, ms = SCRIPT_WAIT): Promise<T | undefined> {
  for (let waited = 0; ; waited += 100) {
    const value = read()
    if (value !== undefined || waited >= ms) {
      return value
    }
    await new Promise(resolve => setTimeout(resolve, 100))
  }
}

function withTimeout<T> (promise: Promise<T>, ms: number, what: string): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | undefined
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => reject(new CaptchaError(`FetchIt: ${what} gave no answer in ${ms / 1000} s`)), ms)
  })
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer))
}

function notLoaded (provider: string, host: string): CaptchaError {
  return new CaptchaError(`FetchIt: the script of ${provider} did not load; is ${host} blocked?`)
}

// The form was taken off the page during the check.
function removed (): CaptchaError {
  return new CaptchaError('FetchIt: the form was removed')
}

function safely (action: () => void) {
  try {
    action()
  } catch (error) {
    console.error(error)
  }
}

/**
 * The block for the widget: before the first submit button, or at the end
 * of the form.
 */
function container (form: HTMLFormElement): HTMLElement {
  const element = document.createElement('div')
  element.className = 'fetchit-captcha'
  const submit = form.querySelector('[type="submit"]')
  if (submit) {
    submit.before(element)
  } else {
    form.append(element)
  }
  return element
}

type Turnstile = NonNullable<Window['turnstile']>
type SmartCaptcha = NonNullable<Window['smartCaptcha']>

/**
 * Cloudflare Turnstile: a widget in the form, rendered as soon as its script
 * is there (or at the first submission, if the script came late).
 */
function turnstile (form: HTMLFormElement, siteKey: string): Captcha {
  let widget: string | undefined
  let failure: string | undefined
  let gone = false
  let box: HTMLElement | undefined

  const render = (api: Turnstile): string => {
    widget ??= api.render(box ??= container(form), {
      sitekey: siteKey,
      callback: () => { failure = undefined },
      // E.g. 110200: the domain is not allowed for the site key.
      'error-callback': code => {
        failure = String(code)
        console.error(`FetchIt: Turnstile error ${code}`)
      },
    })
    return widget
  }
  waitFor(() => gone ? null : window.turnstile).then(api => api && render(api)).catch(error => console.error(error))

  return {
    async answer (formData) {
      const api = await waitFor(() => gone ? null : window.turnstile)
      if (gone) {
        throw removed()
      }
      if (!api) {
        throw notLoaded('Turnstile', 'challenges.cloudflare.com')
      }
      const id = render(api)
      // The widget may still be checking the visitor, or wait for a click.
      const response = await waitFor(() => {
        if (gone) {
          throw removed()
        }
        const current = api.getResponse(id)
        if (current) {
          return current
        }
        if (failure !== undefined) {
          throw new CaptchaError(`FetchIt: Turnstile failed with error ${failure}`)
        }
        return undefined
      }, ANSWER_WAIT)
      if (!response) {
        throw new CaptchaError(`FetchIt: Turnstile gave no answer in ${ANSWER_WAIT / 1000} s`)
      }
      formData.set('cf-turnstile-response', response)
    },
    reset () {
      if (widget !== undefined) {
        const id = widget
        safely(() => window.turnstile?.reset(id))
      }
    },
    destroy () {
      gone = true
      if (widget !== undefined) {
        const id = widget
        widget = undefined
        safely(() => window.turnstile?.remove?.(id))
      }
      box?.remove()
      box = undefined
    },
  }
}

/**
 * Google reCAPTCHA v3: no widget; a fresh answer is asked for each
 * submission.
 */
function recaptcha (siteKey: string): Captcha {
  return {
    async answer (formData) {
      const api = await waitFor(() => window.grecaptcha?.execute ? window.grecaptcha : undefined)
      const execute = api?.execute
      if (!api || !execute) {
        throw notLoaded('reCAPTCHA', 'www.google.com')
      }
      await withTimeout(new Promise<void>(resolve => api.ready ? api.ready(resolve) : resolve()), SCRIPT_WAIT, 'reCAPTCHA')
      const token = await withTimeout(execute(siteKey, { action: RECAPTCHA_ACTION }), ANSWER_WAIT, 'reCAPTCHA')
        .catch((error: unknown) => {
          throw error instanceof CaptchaError ? error : new CaptchaError(`FetchIt: reCAPTCHA failed: ${String(error)}`)
        })
      formData.set('g-recaptcha-response', token)
    },
    reset () {},
    destroy () {},
  }
}

/**
 * Yandex SmartCaptcha, invisible: executed on submission, it shows a puzzle
 * only when it has doubts.
 */
function smartcaptcha (form: HTMLFormElement, siteKey: string): Captcha {
  let widget: number | undefined
  let pending: { resolve (token: string): void; reject (error: Error): void } | undefined
  let gone = false
  let box: HTMLElement | undefined

  const settle = (token: string | undefined, error?: Error) => {
    const waiting = pending
    pending = undefined
    if (error) {
      waiting?.reject(error)
    } else if (token) {
      waiting?.resolve(token)
    }
  }

  const render = (api: SmartCaptcha): number => {
    if (widget === undefined) {
      const id = api.render(box ??= container(form), {
        sitekey: siteKey,
        invisible: true,
        callback: token => settle(token),
      })
      widget = id
      // Closing the puzzle calls no callback. The answer of a solved puzzle
      // may come right after it hides, so give it a moment.
      api.subscribe?.(id, 'challenge-hidden', () => setTimeout(() => {
        if (!api.getResponse(id)) {
          settle(undefined, new CaptchaError('FetchIt: the check of SmartCaptcha was closed'))
        }
      }, 1000))
      api.subscribe?.(id, 'network-error', () => settle(undefined, new CaptchaError('FetchIt: SmartCaptcha could not reach its server')))
      api.subscribe?.(id, 'javascript-error', error => settle(undefined, new CaptchaError(`FetchIt: SmartCaptcha failed: ${JSON.stringify(error)}`)))
    }
    return widget
  }
  waitFor(() => gone ? null : window.smartCaptcha).then(api => api && render(api)).catch(error => console.error(error))

  return {
    async answer (formData) {
      const api = await waitFor(() => gone ? null : window.smartCaptcha)
      if (gone) {
        throw removed()
      }
      if (!api) {
        throw notLoaded('SmartCaptcha', 'smartcaptcha.yandexcloud.net')
      }
      const id = render(api)
      const token = api.getResponse(id) || await withTimeout(new Promise<string>((resolve, reject) => {
        pending = { resolve, reject }
        api.execute(id)
      }), CHALLENGE_WAIT, 'SmartCaptcha')
      formData.set('smart-token', token)
    },
    reset () {
      pending = undefined
      if (widget !== undefined) {
        const id = widget
        safely(() => window.smartCaptcha?.reset(id))
      }
    },
    destroy () {
      gone = true
      // The submission waiting for the answer ends now, not at the timeout.
      settle(undefined, removed())
      if (widget !== undefined) {
        const id = widget
        widget = undefined
        safely(() => window.smartCaptcha?.destroy?.(id))
      }
      box?.remove()
      box = undefined
    },
  }
}

export function createCaptcha (form: HTMLFormElement, config: CaptchaConfig | null | undefined): Captcha | null {
  switch (config?.provider) {
    case 'turnstile':
      return turnstile(form, config.siteKey)
    case 'recaptcha':
      return recaptcha(config.siteKey)
    case 'smartcaptcha':
      return smartcaptcha(form, config.siteKey)
    default:
      return null
  }
}
