// The browser side of the external captchas (FetchItCaptcha on the server).
// Each adapter puts the provider's answer into the form data before a
// submission and resets the widget after it: an answer is good for one check.

export interface CaptchaConfig {
  provider: 'turnstile' | 'recaptcha' | 'smartcaptcha';
  siteKey: string;
}

export interface Captcha {
  answer (formData: FormData): Promise<void>;
  reset (): void;
}

// The reCAPTCHA v3 action the server expects (FetchItCaptcha::RECAPTCHA_ACTION).
const RECAPTCHA_ACTION = 'fetchit'

/**
 * Wait until read() gives something, such as the global of the provider's
 * script, checking every 100 ms (10 s by default).
 */
async function loaded<T> (read: () => T | undefined, tries = 100): Promise<T | undefined> {
  for (let attempt = 0; attempt < tries; attempt++) {
    const api = read()
    if (api) {
      return api
    }
    await new Promise(resolve => setTimeout(resolve, 100))
  }
  return undefined
}

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

/**
 * Cloudflare Turnstile: a widget in the form; its script puts the answer
 * into a hidden cf-turnstile-response field of the form.
 */
function turnstile (form: HTMLFormElement, siteKey: string): Captcha {
  let widget: string | undefined
  loaded(() => window.turnstile).then(api => {
    widget = api?.render(container(form), { sitekey: siteKey })
  })

  return {
    async answer (formData) {
      // The widget may still be checking the visitor, or wait for a click:
      // give it up to 30 s. Without an answer the server refuses the form.
      const api = await loaded(() => window.turnstile)
      const response = api && await loaded(() => widget !== undefined ? api.getResponse(widget) || undefined : undefined, 300)
      if (response) {
        formData.set('cf-turnstile-response', response)
      }
    },
    reset () {
      if (widget !== undefined) {
        window.turnstile?.reset(widget)
      }
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
      const api = await loaded(() => window.grecaptcha?.execute ? window.grecaptcha : undefined)
      if (!api?.execute) {
        return
      }
      await new Promise<void>(resolve => api.ready ? api.ready(resolve) : resolve())
      formData.set('g-recaptcha-response', await api.execute(siteKey, { action: RECAPTCHA_ACTION }))
    },
    reset () {},
  }
}

/**
 * Yandex SmartCaptcha, invisible: executed on submission, it asks the
 * visitor only when it has doubts.
 */
function smartcaptcha (form: HTMLFormElement, siteKey: string): Captcha {
  let widget: number | undefined
  let resolveAnswer: ((token: string) => void) | undefined
  loaded(() => window.smartCaptcha).then(api => {
    widget = api?.render(container(form), {
      sitekey: siteKey,
      invisible: true,
      callback: (token: string) => resolveAnswer?.(token),
    })
  })

  return {
    async answer (formData) {
      const api = await loaded(() => window.smartCaptcha)
      if (!api || widget === undefined) {
        return
      }
      const current = api.getResponse(widget)
      const token = current || await new Promise<string>(resolve => {
        resolveAnswer = resolve
        api.execute(widget!)
      })
      formData.set('smart-token', token)
    },
    reset () {
      if (widget !== undefined) {
        window.smartCaptcha?.reset(widget)
      }
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
