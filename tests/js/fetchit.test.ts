import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createHash } from 'node:crypto'
import '../../src/index'

// Node's Request, which the class uses, needs an absolute URL.
const actionUrl = 'http://localhost/assets/components/fetchit/action.php'
const action = '0123456789abcdef0123456789abcdef'

const FetchIt = window.FetchIt

type Config = ConstructorParameters<typeof FetchIt>[1]
type Handler = (event: CustomEvent) => void

function config(overrides: Partial<Config> = {}): Config {
  return {
    action,
    actionUrl,
    pageId: 5,
    inputInvalidClass: 'is-invalid',
    customInvalidClass: 'has-error',
    clearFieldsOnSuccess: true,
    defaultNotifier: false,
    ...overrides,
  }
}

function mountForm() {
  document.body.innerHTML = `
    <form data-fetchit="${action}">
      <input name="name" value="Ann">
      <input name="email" value="">
      <div data-custom="email"></div>
      <span data-error="email" style="display: none"></span>
      <span data-error="name" style="display: none"></span>
      <button type="submit">Send</button>
      <div data-success style="display: none"></div>
      <div data-validation-error style="display: none"></div>
    </form>`
  return document.querySelector('form') as HTMLFormElement
}

function field(form: HTMLFormElement, name: string) {
  return form.elements.namedItem(name) as HTMLInputElement
}

function element(form: HTMLFormElement, selector: string) {
  return form.querySelector(selector) as HTMLElement
}

function respond(body: unknown) {
  const fetch = vi.fn(async (_request: Request, _init?: RequestInit) => ({ json: async () => body }))
  vi.stubGlobal('fetch', fetch)
  return fetch
}

function answerWith(body: unknown, status = 200) {
  return vi.fn(async () => ({ status, url: actionUrl, json: async () => body }))
}

async function submit(form: HTMLFormElement) {
  form.dispatchEvent(new Event('submit', { cancelable: true }))
  // The handler awaits fetch() and json(); let both settle.
  await new Promise(resolve => setTimeout(resolve, 0))
}

let listeners: [string, EventListener][] = []

function on(name: string, handler: Handler) {
  document.addEventListener(name, handler as EventListener)
  listeners.push([name, handler as EventListener])
}

beforeEach(() => {
  delete FetchIt.Message
})

afterEach(() => {
  listeners.forEach(([name, handler]) => document.removeEventListener(name, handler))
  listeners = []
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('FetchIt.create', () => {
  it('binds every form with the matching action', () => {
    const form = mountForm()
    FetchIt.create(config())

    expect(FetchIt.instances.get(form)).toBeInstanceOf(FetchIt)
  })

  it('requires an action', () => {
    expect(() => FetchIt.create(config({ action: '' }))).toThrow()
  })

  it('turns the built-in notifier on when the config asks for it', async () => {
    const form = mountForm()
    FetchIt.create(config({ defaultNotifier: true, notifierCloseLabel: 'Закрыть' }))
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [] }))

    await submit(form)

    const toast = document.querySelector('.fetchit-toast[data-type="success"]')
    expect(toast?.textContent).toContain('Thanks')
    expect(toast?.querySelector('button')?.getAttribute('aria-label')).toBe('Закрыть')
  })

  it('leaves a FetchIt.Message of the site alone', async () => {
    const own = { success: vi.fn() }
    FetchIt.Message = own
    const form = mountForm()
    FetchIt.create(config({ defaultNotifier: true }))
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [] }))

    await submit(form)

    expect(FetchIt.Message).toBe(own)
    expect(own.success).toHaveBeenCalledWith('Thanks')
    expect(document.querySelector('.fetchit-toast')).toBeNull()
  })

  it('gives the built-in notifier to sites without the setting', () => {
    FetchIt.createNotifier().error('Failed')

    expect(document.querySelector('.fetchit-toast[data-type="error"]')?.textContent).toContain('Failed')
  })

  it('shows no toasts without the setting', async () => {
    const form = mountForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [] }))

    await submit(form)

    expect(FetchIt.Message).toBeUndefined()
    expect(document.querySelector('.fetchit-toast')).toBeNull()
  })

  it('adds the built-in toasts to a FetchIt.Message with neither success nor error', async () => {
    // E.g. a spinner in before and after.
    const before = vi.fn()
    FetchIt.Message = { before }
    const form = mountForm()
    FetchIt.create(config({ defaultNotifier: true }))
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [] }))

    await submit(form)

    expect(before).toHaveBeenCalled()
    expect(document.querySelector('.fetchit-toast[data-type="success"]')?.textContent).toContain('Thanks')
  })

  it('names the hook that threw', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    FetchIt.Message = { success: () => { throw new Error('broken') } }
    const form = mountForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [] }))

    await submit(form)

    expect(String(vi.mocked(console.error).mock.calls[0]![0])).toContain('FetchIt.Message.success() threw')
    expect(element(form, '[data-success]').textContent).toBe('Thanks')
  })

  it('has no form data before the first submission', () => {
    const form = mountForm()
    FetchIt.create(config())

    expect(FetchIt.instances.get(form)!.formData).toBeUndefined()
  })

  it('rejects anything but a form', () => {
    // For scripts without types: the types allow only a form.
    expect(() => new FetchIt(document.createElement('div') as unknown as HTMLFormElement, config())).toThrow()
  })
})

describe('submitting', () => {
  it('posts the form with the action header and the page id', async () => {
    const form = mountForm()
    FetchIt.create(config())
    const fetch = respond({ success: true, message: 'Sent', data: [] })

    await submit(form)

    expect(fetch).toHaveBeenCalledOnce()
    const [request, init] = fetch.mock.calls[0]!
    const body = init?.body as FormData
    expect(request.url).toBe(actionUrl)
    expect(request.method).toBe('POST')
    expect(request.headers.get('X-FetchIt-Action')).toBe(action)
    expect(body.get('pageId')).toBe('5')
    expect(body.get('name')).toBe('Ann')
  })

  it('does not send when fetchit:before is cancelled', async () => {
    const form = mountForm()
    FetchIt.create(config())
    const fetch = respond({ success: true, message: '', data: [] })
    on('fetchit:before', event => event.preventDefault())

    await submit(form)

    expect(fetch).not.toHaveBeenCalled()
  })

  it('lets fetchit:before add to the form data', async () => {
    const form = mountForm()
    FetchIt.create(config())
    const fetch = respond({ success: true, message: '', data: [] })
    on('fetchit:before', ({ detail }) => detail.formData.set('extra', '1'))

    await submit(form)

    const init = fetch.mock.calls[0]![1]!
    expect((init.body as FormData).get('extra')).toBe('1')
  })

  it('disables the fields while the request is in flight', async () => {
    const form = mountForm()
    FetchIt.create(config())
    let disabledDuringRequest: boolean | undefined
    vi.stubGlobal('fetch', vi.fn(async () => {
      disabledDuringRequest = field(form, 'email').disabled
      return { json: async () => ({ success: true, message: '', data: [] }) }
    }))

    await submit(form)

    expect(disabledDuringRequest).toBe(true)
    expect(field(form, 'email').disabled).toBe(false)
  })
})

describe('validation errors', () => {
  const failure = {
    success: false,
    message: 'The form has errors',
    data: { email: 'Required <b>field</b>', name: '   ' },
  }

  it('shows field errors as plain text and marks the fields', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond(failure)

    await submit(form)

    const email = field(form, 'email')
    const error = element(form, '[data-error="email"]')
    expect(error.textContent).toBe('Required field')
    expect(error.style.display).toBe('')
    expect(email.classList.contains('is-invalid')).toBe(true)
    expect(email.getAttribute('aria-invalid')).toBe('true')
    expect(element(form, '[data-custom="email"]').classList.contains('has-error')).toBe(true)
  })

  it('ignores blank error messages', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond(failure)

    await submit(form)

    expect(field(form, 'name').classList.contains('is-invalid')).toBe(false)
    expect(element(form, '[data-error="name"]').style.display).toBe('none')
  })

  it('shows the form message and fires fetchit:error', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond(failure)
    const onError = vi.fn<Handler>()
    on('fetchit:error', onError)

    await submit(form)

    const message = element(form, '[data-validation-error]')
    expect(message.textContent).toBe('The form has errors')
    expect(message.style.display).toBe('')
    expect(onError).toHaveBeenCalledOnce()
    expect(onError.mock.calls[0]![0].detail.response).toEqual(failure)
  })

  it('clears a field error as soon as the field changes', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond(failure)
    await submit(form)

    field(form, 'email').dispatchEvent(new Event('input', { bubbles: true }))

    expect(field(form, 'email').classList.contains('is-invalid')).toBe(false)
    expect(element(form, '[data-error="email"]').textContent).toBe('')
  })

  it('passes the message to FetchIt.Message.error', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond(failure)
    FetchIt.Message = { error: vi.fn(), success: vi.fn() }

    await submit(form)

    expect(FetchIt.Message.error).toHaveBeenCalledWith('The form has errors')
    expect(FetchIt.Message.success).not.toHaveBeenCalled()
  })
})

function listen() {
  const details: Record<string, unknown> = {}
  for (const name of Object.values(FetchIt.events)) {
    on(name, event => { details[name] = (event as CustomEvent).detail })
  }
  return details
}

describe('the detail of the events', () => {
  it('has what the public types promise on a success', async () => {
    const form = mountForm()
    FetchIt.create(config())
    const response = { success: true, message: 'Thank you', data: [] }
    respond(response)
    const details = listen()

    await submit(form)

    const fetchit = FetchIt.instances.get(form)
    const formData = fetchit!.formData
    expect(formData).toBeInstanceOf(FormData)
    expect(details['fetchit:before']).toEqual({ form, formData, fetchit })
    expect(details['fetchit:after']).toEqual({ form, formData, response, fetchit })
    expect(details['fetchit:success']).toEqual({ form, formData, response, fetchit })
    expect(details['fetchit:reset']).toEqual({ form, fetchit })
    expect(details['fetchit:error']).toBeUndefined()
  })

  it('has the response on a refusal and the error on a failure', async () => {
    const form = mountForm()
    FetchIt.create(config())
    const details = listen()
    const response = { success: false, message: 'Check the form', data: { email: 'Required' } }
    respond(response)

    await submit(form)
    const fetchit = FetchIt.instances.get(form)
    expect(details['fetchit:error']).toEqual({ form, formData: fetchit!.formData, response, fetchit })

    vi.spyOn(console, 'error').mockImplementation(() => {})
    const failure = new TypeError('Failed to fetch')
    vi.stubGlobal('fetch', vi.fn(async () => { throw failure }))
    await submit(form)
    expect(details['fetchit:error']).toEqual({ form, formData: fetchit!.formData, response: null, error: failure, fetchit })
  })

  it('gets a message and data even when the snippet sent none', async () => {
    const form = mountForm()
    const success = vi.fn()
    FetchIt.Message = { success }
    FetchIt.create(config())
    respond({ success: true })
    const details = listen()

    await submit(form)

    expect(success).toHaveBeenCalledWith('')
    expect((details['fetchit:success'] as { response: FetchItResponse }).response).toEqual({ success: true, message: '', data: {} })
  })

  it('gets a message that is text even when the snippet sent a number', async () => {
    const form = mountForm()
    const error = vi.fn()
    FetchIt.Message = { error }
    FetchIt.create(config())
    respond({ success: false, message: 404, data: null })

    await submit(form)

    expect(error).toHaveBeenCalledWith('404')
  })
})

describe('success', () => {
  const success = { success: true, message: 'Thank you', data: [] }

  it('shows the success message, fires fetchit:success and resets the form', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond(success)
    const onSuccess = vi.fn<Handler>()
    on('fetchit:success', onSuccess)
    field(form, 'email').value = 'ann@example.com'

    await submit(form)

    const message = element(form, '[data-success]')
    expect(message.textContent).toBe('Thank you')
    expect(message.style.display).toBe('')
    expect(onSuccess).toHaveBeenCalledOnce()
    // reset() restores the initial value from the markup.
    expect(field(form, 'email').value).toBe('')
  })

  it('keeps the fields when a fetchit:success handler cancels it', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond(success)
    on('fetchit:success', event => event.preventDefault())
    field(form, 'email').value = 'ann@example.com'

    await submit(form)

    expect(element(form, '[data-success]').textContent).toBe('Thank you')
    expect(field(form, 'email').value).toBe('ann@example.com')
  })

  it('keeps the fields when clearFieldsOnSuccess is off', async () => {
    const form = mountForm()
    FetchIt.create(config({ clearFieldsOnSuccess: false }))
    respond(success)
    field(form, 'email').value = 'ann@example.com'

    await submit(form)

    expect(field(form, 'email').value).toBe('ann@example.com')
  })

  it('calls notifier hooks as methods of FetchIt.Message', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond(success)
    const shown: string[] = []
    FetchIt.Message = {
      prefix: '>',
      success(this: { prefix: string }, message: string) { shown.push(this.prefix + message) },
    } as never

    await submit(form)

    expect(shown).toEqual(['>Thank you'])
  })

  it('passes the message to FetchIt.Message.success', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond(success)
    FetchIt.Message = { error: vi.fn(), success: vi.fn() }

    await submit(form)

    expect(FetchIt.Message.success).toHaveBeenCalledWith('Thank you')
  })
})

describe('reset', () => {
  it('fires fetchit:reset and clears errors and messages', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond({ success: false, message: 'Errors', data: { email: 'Required' } })
    await submit(form)
    const onReset = vi.fn<Handler>()
    on('fetchit:reset', onReset)

    form.reset()

    expect(onReset).toHaveBeenCalledOnce()
    expect(field(form, 'email').classList.contains('is-invalid')).toBe(false)
    expect(element(form, '[data-validation-error]').style.display).toBe('none')
  })
})

describe('binding and fields', () => {
  it('ignores a second submit while the first request is in flight', async () => {
    const form = mountForm()
    FetchIt.create(config())
    let finish: (() => void) | undefined
    const fetch = vi.fn(() => new Promise(resolve => {
      finish = () => resolve({ json: async () => ({ success: true, message: '', data: [] }) })
    }))
    vi.stubGlobal('fetch', fetch)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await new Promise(resolve => setTimeout(resolve, 0))
    finish?.()
    await new Promise(resolve => setTimeout(resolve, 0))

    expect(fetch).toHaveBeenCalledOnce()
    expect(field(form, 'email').disabled).toBe(false)
  })

  it('binds a form once when create() runs twice for the same action', async () => {
    // Two identical snippet calls give the same action and two create() calls.
    const form = mountForm()
    FetchIt.create(config())
    FetchIt.create(config())
    const fetch = respond({ success: true, message: '', data: [] })

    await submit(form)

    expect(fetch).toHaveBeenCalledOnce()
  })

  it('keeps fields disabled that were disabled before the request', async () => {
    const form = mountForm()
    field(form, 'name').disabled = true
    FetchIt.create(config())
    respond({ success: true, message: '', data: [] })

    await submit(form)

    expect(field(form, 'name').disabled).toBe(true)
    expect(field(form, 'email').disabled).toBe(false)
  })

  it('does not throw when no form matches', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

    expect(() => FetchIt.create(config())).not.toThrow()
    expect(warn).toHaveBeenCalled()
  })

  it('handles field names with backslashes and custom error elements', async () => {
    document.body.innerHTML = `
      <form data-fetchit="${action}">
        <input name="a\\b" value="">
        <div data-custom="a\\b"></div>
      </form>`
    const form = document.querySelector('form') as HTMLFormElement
    FetchIt.create(config())
    respond({ success: false, message: 'Errors', data: { 'a\\b': 'Required' } })

    await submit(form)

    expect(field(form, 'a\\b').getAttribute('aria-invalid')).toBe('true')
    expect(element(form, '[data-custom]').classList.contains('has-error')).toBe(true)
  })

  it('handles field names with quotes', async () => {
    document.body.innerHTML = `
      <form data-fetchit="${action}">
        <input name='say"hi' value="">
        <span data-error='say"hi'></span>
      </form>`
    const form = document.querySelector('form') as HTMLFormElement
    FetchIt.create(config())
    respond({ success: false, message: 'Errors', data: { 'say"hi': 'Required' } })
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})

    await submit(form)

    expect(error).not.toHaveBeenCalled()
    expect(element(form, '[data-error]').textContent).toBe('Required')
  })
})

describe('failed requests', () => {
  const requestErrorMessage = 'Could not send the form'

  async function failWith(fetch: ReturnType<typeof vi.fn>) {
    const form = mountForm()
    FetchIt.create(config({ requestErrorMessage }))
    vi.stubGlobal('fetch', fetch)
    vi.spyOn(console, 'error').mockImplementation(() => {})
    FetchIt.Message = { error: vi.fn(), success: vi.fn() }
    const onError = vi.fn<Handler>()
    on('fetchit:error', onError)

    await submit(form)

    return { form, onError }
  }

  const cases = {
    'a response that is not JSON': vi.fn(async () => ({ json: async () => { throw new SyntaxError('Unexpected token <') } })),
    'a network failure': vi.fn(async () => { throw new TypeError('Failed to fetch') }),
    'JSON from something else (a firewall)': answerWith({ error: 'Forbidden' }, 403),
    'a null answer': answerWith(null),
  }

  for (const [name, fetch] of Object.entries(cases)) {
    it(`tells the visitor about ${name}`, async () => {
      const { form, onError } = await failWith(fetch)

      expect(FetchIt.Message?.error).toHaveBeenCalledWith(requestErrorMessage)
      expect(element(form, '[data-validation-error]').textContent).toBe(requestErrorMessage)
      expect(onError).toHaveBeenCalledOnce()
      expect(onError.mock.calls[0]![0].detail.response).toBeNull()
      expect(onError.mock.calls[0]![0].detail.error).toBeInstanceOf(Error)
      expect(field(form, 'email').disabled).toBe(false)
    })
  }

  it('falls back to a built-in message when the config has none', async () => {
    const form = mountForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', cases['a network failure'])
    vi.spyOn(console, 'error').mockImplementation(() => {})

    await submit(form)

    expect(element(form, '[data-validation-error]').textContent).toBe(FetchIt.defaultRequestErrorMessage)
  })

  it('leaves the form message to a handler that cancels fetchit:error', async () => {
    const form = mountForm()
    FetchIt.create(config({ requestErrorMessage }))
    vi.stubGlobal('fetch', cases['a network failure'])
    vi.spyOn(console, 'error').mockImplementation(() => {})
    on('fetchit:error', event => event.preventDefault())

    await submit(form)

    expect(element(form, '[data-validation-error]').textContent).toBe('')
  })

  it('does not report a failure after the server accepted the form', async () => {
    const form = mountForm()
    FetchIt.create(config({ requestErrorMessage }))
    respond({ success: true, message: 'Thank you', data: [] })
    vi.spyOn(console, 'error').mockImplementation(() => {})
    FetchIt.Message = { error: vi.fn(), after: () => { throw new Error('broken notifier') } }

    await submit(form)

    // Telling the visitor it failed would make them send it again.
    expect(FetchIt.Message.error).not.toHaveBeenCalled()
    expect(element(form, '[data-success]').textContent).toBe('Thank you')
  })

  it('shows the answer even when the notifier throws', async () => {
    const form = mountForm()
    FetchIt.create(config({ requestErrorMessage }))
    respond({ success: false, message: 'Errors', data: { email: 'Required' } })
    const logged = vi.spyOn(console, 'error').mockImplementation(() => {})
    const onError = vi.fn<Handler>()
    on('fetchit:error', onError)
    FetchIt.Message = { error: () => { throw new Error('broken notifier') } }

    await submit(form)

    expect(element(form, '[data-error="email"]').textContent).toBe('Required')
    expect(element(form, '[data-validation-error]').textContent).toBe('Errors')
    expect(onError).toHaveBeenCalledOnce()
    expect(logged).toHaveBeenCalled()
    expect(field(form, 'email').disabled).toBe(false)
  })

  it('shows a failed request even when the notifier throws', async () => {
    const form = mountForm()
    FetchIt.create(config({ requestErrorMessage }))
    vi.stubGlobal('fetch', cases['a network failure'])
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const onError = vi.fn<Handler>()
    on('fetchit:error', onError)
    FetchIt.Message = { error: () => { throw new Error('broken notifier') } }

    await submit(form)

    expect(element(form, '[data-validation-error]').textContent).toBe(requestErrorMessage)
    expect(onError).toHaveBeenCalledOnce()
  })

  it('accepts an answer without data', async () => {
    const form = mountForm()
    FetchIt.create(config({ requestErrorMessage }))
    respond({ success: false, message: 'Try later' })
    FetchIt.Message = { error: vi.fn() }

    await submit(form)

    expect(FetchIt.Message.error).toHaveBeenCalledWith('Try later')
    expect(FetchIt.Message.error).not.toHaveBeenCalledWith(requestErrorMessage)
    expect(element(form, '[data-validation-error]').textContent).toBe('Try later')
  })
})

function mountProtectedForm() {
  document.body.innerHTML = `
    <form data-fetchit="${action}">
      <input type="hidden" name="fetchit_token" value="old-token">
      <input name="email" value="">
      <div data-success style="display: none"></div>
      <div data-validation-error style="display: none"></div>
      <button type="submit">Send</button>
    </form>`
  return document.querySelector('form') as HTMLFormElement
}

function answerSequence(...answers: { body: unknown, headers: Record<string, string> }[]) {
  const fetch = vi.fn(async (_request: Request, _init?: RequestInit) => {
    const answer = answers.shift()!
    return { headers: new Headers(answer.headers), json: async () => answer.body }
  })
  vi.stubGlobal('fetch', fetch)
  return fetch
}

function answerWithToken(body: unknown, token: string | null) {
  return vi.fn(async (_request: Request, _init?: RequestInit) => ({
    headers: new Headers(token ? { 'X-FetchIt-Token': token } : {}),
    json: async () => body,
  }))
}

describe('spam protection', () => {
  it('sends the token of the form', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config())
    const fetch = answerWithToken({ success: true, message: '', data: [] }, 'next-token')
    vi.stubGlobal('fetch', fetch)

    await submit(form)

    expect((fetch.mock.calls[0]![1]!.body as FormData).get('fetchit_token')).toBe('old-token')
  })

  it('takes the next token from the answer, also after a reset', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWithToken({ success: true, message: 'Sent', data: [] }, 'next-token'))

    await submit(form)

    // clearFieldsOnSuccess resets the form: the token must survive it.
    expect(field(form, 'fetchit_token').value).toBe('next-token')
  })

  it('takes the next token from a refusal too', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWithToken({ success: false, message: 'Too fast', data: [] }, 'retry-token'))

    await submit(form)

    expect(field(form, 'fetchit_token').value).toBe('retry-token')
  })

  it('sends once more by itself when the page had a stale token', async () => {
    // A page from a full-page cache, or open for a day, holds a used or
    // expired token: the visitor must not see "expired" for it.
    const form = mountProtectedForm()
    FetchIt.create(config())
    const fetch = answerSequence(
      { body: { success: false, message: 'Expired', data: [] }, headers: { 'X-FetchIt-Token': 'fresh', 'X-FetchIt-Refused': 'token' } },
      { body: { success: true, message: 'Sent', data: [] }, headers: { 'X-FetchIt-Token': 'after' } },
    )

    await submit(form)

    expect(fetch).toHaveBeenCalledTimes(2)
    expect((fetch.mock.calls[1]![1]!.body as FormData).get('fetchit_token')).toBe('fresh')
    expect(element(form, '[data-success]').textContent).toBe('Sent')
    expect(field(form, 'fetchit_token').value).toBe('after')
  })

  it('sends again only once', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config())
    const refused = { body: { success: false, message: 'Expired', data: [] }, headers: { 'X-FetchIt-Token': 'fresh', 'X-FetchIt-Refused': 'token' } }
    const fetch = answerSequence(refused, { ...refused })

    await submit(form)

    expect(fetch).toHaveBeenCalledTimes(2)
  })

  it('does not send again for other refusals or without a new token', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config())
    const fetch = answerSequence(
      { body: { success: false, message: 'Too fast', data: [] }, headers: { 'X-FetchIt-Token': 'retry', 'X-FetchIt-Refused': 'too_fast' } },
    )
    await submit(form)
    expect(fetch).toHaveBeenCalledOnce()

    const again = answerSequence({ body: { success: false, message: 'Expired', data: [] }, headers: { 'X-FetchIt-Refused': 'token' } })
    await submit(form)
    expect(again).toHaveBeenCalledOnce()
  })

  it('keeps the token when the answer has none', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWithToken({ success: false, message: 'x', data: [] }, null))

    await submit(form)

    expect(field(form, 'fetchit_token').value).toBe('old-token')
  })
})

function zeroBitsOf(input: string) {
  const digest = createHash('sha256').update(input).digest()
  let bits = 0
  for (const byte of digest) {
    if (byte === 0) {
      bits += 8
      continue
    }
    return bits + Math.clz32(byte) - 24
  }
  return bits
}

describe('proof of work', () => {
  it('sends a solution for the token of the form', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ pow: 8 }))
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, 'next-token')
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

    const body = fetch.mock.calls[0]![1]!.body as FormData
    expect(zeroBitsOf(`old-token:${body.get('fetchit_pow')}`)).toBeGreaterThanOrEqual(8)
  })

  it('solves again for the new token when it sends once more', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ pow: 8 }))
    const fetch = answerSequence(
      { body: { success: false, message: 'Expired', data: [] }, headers: { 'X-FetchIt-Token': 'fresh', 'X-FetchIt-Refused': 'token' } },
      { body: { success: true, message: 'Sent', data: [] }, headers: {} },
    )

    await submit(form)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))

    const body = fetch.mock.calls[1]![1]!.body as FormData
    expect(body.get('fetchit_token')).toBe('fresh')
    expect(zeroBitsOf(`fresh:${body.get('fetchit_pow')}`)).toBeGreaterThanOrEqual(8)
  })

  it('sends nothing extra without a proof of work', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config())
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    await submit(form)

    expect((fetch.mock.calls[0]![1]!.body as FormData).has('fetchit_pow')).toBe(false)
  })

  it('solves and sends again when the server asks for more than the page', async () => {
    // A page from a cache made before the proof of work was turned on.
    const form = mountProtectedForm()
    const settings = config()
    FetchIt.create(settings)
    const fetch = answerSequence(
      { body: { success: false, message: 'Reload', data: [] }, headers: { 'X-FetchIt-Token': 'fresh', 'X-FetchIt-Refused': 'pow', 'X-FetchIt-Pow': '8' } },
      { body: { success: true, message: 'Sent', data: [] }, headers: {} },
    )

    await submit(form)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))

    const body = fetch.mock.calls[1]![1]!.body as FormData
    expect(zeroBitsOf(`fresh:${body.get('fetchit_pow')}`)).toBeGreaterThanOrEqual(8)
    await vi.waitFor(() => expect(element(form, '[data-success]').textContent).toBe('Sent'))
    expect(settings.pow).toBe(8)
  })

  it('does not send again for a refusal it cannot fix', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ pow: 8 }))
    const fetch = answerSequence(
      { body: { success: false, message: 'Reload', data: [] }, headers: { 'X-FetchIt-Token': 'fresh', 'X-FetchIt-Refused': 'pow', 'X-FetchIt-Pow': '8' } },
    )

    await submit(form)
    await vi.waitFor(() => expect(element(form, '[data-validation-error]').textContent).toBe('Reload'))

    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('sends once while it is still solving', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ pow: 12 }))
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await vi.waitFor(() => expect(element(form, '[data-success]').textContent).toBe('Sent'))

    expect(fetch).toHaveBeenCalledTimes(1)
  })
})

function captchaConfig(provider: 'turnstile' | 'recaptcha' | 'smartcaptcha') {
  return config({ captcha: { provider, siteKey: 'site-key' }, captchaErrorMessage: 'The check could not be completed' })
}

function fakeTurnstile(getResponse: () => string | undefined = () => 'turnstile-answer') {
  return {
    render: vi.fn((_element: HTMLElement, _options: { 'error-callback'?: (code: string) => void }) => 'widget-1'),
    getResponse: vi.fn(getResponse),
    reset: vi.fn(),
  }
}

function fakeSmartCaptcha() {
  const handlers = new Map<string, () => void>()
  return {
    handlers,
    render: vi.fn((_element: HTMLElement, _options: { callback?: (token: string) => void }) => 7),
    getResponse: vi.fn(() => ''),
    execute: vi.fn(),
    reset: vi.fn(),
    subscribe: vi.fn((_widget: number, event: string, handler: () => void) => {
      handlers.set(event, handler)
      return () => {}
    }),
  }
}

function sendsAgain(form: HTMLFormElement) {
  expect(field(form, 'email').hasAttribute('disabled')).toBe(false)
}

describe('captchas', () => {
  afterEach(() => {
    delete window.turnstile
    delete window.smartCaptcha
    delete window.grecaptcha
  })

  it('reCAPTCHA v3: asks for an answer with the action the server expects', async () => {
    const execute = vi.fn(async () => 'recaptcha-answer')
    window.grecaptcha = { ready: callback => callback(), execute, reset: vi.fn() }
    const form = mountProtectedForm()
    FetchIt.create(config({ captcha: { provider: 'recaptcha', siteKey: 'site-key' } }))
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

    expect(execute).toHaveBeenCalledWith('site-key', { action: 'fetchit' })
    expect((fetch.mock.calls[0]![1]!.body as FormData).get('g-recaptcha-response')).toBe('recaptcha-answer')
    expect(window.grecaptcha.reset).not.toHaveBeenCalled()
  })

  it('Turnstile: renders a widget in the form, sends its answer and resets it', async () => {
    const turnstile = {
      render: vi.fn(() => 'widget-1'),
      getResponse: vi.fn(() => 'turnstile-answer'),
      reset: vi.fn(),
    }
    window.turnstile = turnstile
    const form = mountProtectedForm()
    FetchIt.create(config({ captcha: { provider: 'turnstile', siteKey: 'site-key' } }))
    await vi.waitFor(() => expect(turnstile.render).toHaveBeenCalled())
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await vi.waitFor(() => expect(turnstile.reset).toHaveBeenCalledWith('widget-1'))

    const [widget, options] = turnstile.render.mock.calls[0] as unknown as [HTMLElement, { sitekey: string }]
    expect(form.contains(widget)).toBe(true)
    expect(options.sitekey).toBe('site-key')
    expect((fetch.mock.calls[0]![1]!.body as FormData).get('cf-turnstile-response')).toBe('turnstile-answer')
  })

  it('SmartCaptcha: executes the invisible widget and sends its answer', async () => {
    let callback: ((token: string) => void) | undefined
    const smartCaptcha = {
      render: vi.fn((_element: HTMLElement, options: { callback?: (token: string) => void }) => {
        callback = options.callback
        return 7
      }),
      getResponse: vi.fn(() => ''),
      execute: vi.fn(() => setTimeout(() => callback?.('smart-answer'), 0)),
      reset: vi.fn(),
    }
    window.smartCaptcha = smartCaptcha
    const form = mountProtectedForm()
    FetchIt.create(config({ captcha: { provider: 'smartcaptcha', siteKey: 'site-key' } }))
    await vi.waitFor(() => expect(smartCaptcha.render).toHaveBeenCalled())
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

    expect(smartCaptcha.render.mock.calls[0]![1]).toMatchObject({ sitekey: 'site-key', invisible: true })
    expect(smartCaptcha.execute).toHaveBeenCalledWith(7)
    expect((fetch.mock.calls[0]![1]!.body as FormData).get('smart-token')).toBe('smart-answer')
    await vi.waitFor(() => expect(smartCaptcha.reset).toHaveBeenCalledWith(7))
  })

  it('Turnstile: waits while the widget is still checking', async () => {
    const answers = ['', '', 'late-answer']
    const turnstile = fakeTurnstile(() => answers.shift() ?? 'late-answer')
    window.turnstile = turnstile
    const form = mountProtectedForm()
    FetchIt.create(captchaConfig('turnstile'))
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

    expect((fetch.mock.calls[0]![1]!.body as FormData).get('cf-turnstile-response')).toBe('late-answer')
  })

  it('Turnstile: a widget that failed is not waited for', async () => {
    const turnstile = fakeTurnstile(() => '')
    window.turnstile = turnstile
    const form = mountProtectedForm()
    FetchIt.create(captchaConfig('turnstile'))
    await vi.waitFor(() => expect(turnstile.render).toHaveBeenCalled())
    vi.spyOn(console, 'error').mockImplementation(() => {})
    turnstile.render.mock.calls[0]![1]['error-callback']!('110200')
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await vi.waitFor(() => expect(element(form, '[data-validation-error]').textContent).toBe('The check could not be completed'))

    expect(fetch).not.toHaveBeenCalled()
    expect(console.error).toHaveBeenCalledWith('FetchIt: Turnstile error 110200')
    sendsAgain(form)
  })

  it('a script that does not load is reported, not sent to be refused', async () => {
    vi.useFakeTimers()
    try {
      vi.spyOn(console, 'error').mockImplementation(() => {})
      const form = mountProtectedForm()
      FetchIt.create(captchaConfig('turnstile'))
      const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
      vi.stubGlobal('fetch', fetch)

      form.dispatchEvent(new Event('submit', { cancelable: true }))
      await vi.advanceTimersByTimeAsync(10_500)

      expect(fetch).not.toHaveBeenCalled()
      expect(element(form, '[data-validation-error]').textContent).toBe('The check could not be completed')
      expect(String(vi.mocked(console.error).mock.calls[0]![0])).toContain('challenges.cloudflare.com')
      sendsAgain(form)
    } finally {
      vi.useRealTimers()
    }
  })

  it('Turnstile: a script that came late renders the widget at the submission', async () => {
    vi.useFakeTimers()
    try {
      const form = mountProtectedForm()
      FetchIt.create(captchaConfig('turnstile'))
      await vi.advanceTimersByTimeAsync(12_000)
      const turnstile = fakeTurnstile()
      window.turnstile = turnstile
      const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
      vi.stubGlobal('fetch', fetch)

      form.dispatchEvent(new Event('submit', { cancelable: true }))
      await vi.advanceTimersByTimeAsync(500)

      expect(turnstile.render).toHaveBeenCalledTimes(1)
      expect((fetch.mock.calls[0]![1]!.body as FormData).get('cf-turnstile-response')).toBe('turnstile-answer')
    } finally {
      vi.useRealTimers()
    }
  })

  it('sends the same answer again with a new token', async () => {
    // The server checks the captcha last: a "token" refusal did not use it.
    const turnstile = fakeTurnstile()
    window.turnstile = turnstile
    const form = mountProtectedForm()
    FetchIt.create(captchaConfig('turnstile'))
    await vi.waitFor(() => expect(turnstile.render).toHaveBeenCalled())
    const fetch = answerSequence(
      { body: { success: false, message: 'Expired', data: [] }, headers: { 'X-FetchIt-Token': 'fresh', 'X-FetchIt-Refused': 'token' } },
      { body: { success: true, message: 'Sent', data: [] }, headers: {} },
    )

    await submit(form)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))

    expect((fetch.mock.calls[1]![1]!.body as FormData).get('cf-turnstile-response')).toBe('turnstile-answer')
    expect(turnstile.getResponse).toHaveBeenCalledTimes(1)
  })

  it('a widget whose reset throws does not lock the form', async () => {
    const turnstile = fakeTurnstile()
    turnstile.reset.mockImplementation(() => { throw new Error('widget removed') })
    window.turnstile = turnstile
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountProtectedForm()
    FetchIt.create(captchaConfig('turnstile'))
    await vi.waitFor(() => expect(turnstile.render).toHaveBeenCalled())
    const fetch = answerWithToken({ success: false, message: 'Check the form', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await vi.waitFor(() => expect(turnstile.reset).toHaveBeenCalled())
    sendsAgain(form)
    await submit(form)

    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))
  })

  it('reCAPTCHA v3: a failed request is reported', async () => {
    window.grecaptcha = { ready: callback => callback(), execute: vi.fn(async () => { throw null }) }
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountProtectedForm()
    FetchIt.create(captchaConfig('recaptcha'))
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await vi.waitFor(() => expect(element(form, '[data-validation-error]').textContent).toBe('The check could not be completed'))

    expect(fetch).not.toHaveBeenCalled()
    sendsAgain(form)
  })

  it('SmartCaptcha: sends an answer it already has without a new check', async () => {
    const smartCaptcha = fakeSmartCaptcha()
    smartCaptcha.getResponse.mockReturnValue('ready-answer')
    window.smartCaptcha = smartCaptcha
    const form = mountProtectedForm()
    FetchIt.create(captchaConfig('smartcaptcha'))
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

    expect(smartCaptcha.execute).not.toHaveBeenCalled()
    expect((fetch.mock.calls[0]![1]!.body as FormData).get('smart-token')).toBe('ready-answer')
  })

  it('SmartCaptcha: closing the check gives the form back', async () => {
    const smartCaptcha = fakeSmartCaptcha()
    window.smartCaptcha = smartCaptcha
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountProtectedForm()
    FetchIt.create(captchaConfig('smartcaptcha'))
    await vi.waitFor(() => expect(smartCaptcha.subscribe).toHaveBeenCalled())
    const fetch = answerWithToken({ success: true, message: 'Sent', data: [] }, null)
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await vi.waitFor(() => expect(smartCaptcha.execute).toHaveBeenCalledWith(7))
    smartCaptcha.handlers.get('challenge-hidden')!()
    await vi.waitFor(() => expect(element(form, '[data-validation-error]').textContent).toBe('The check could not be completed'), { timeout: 3000 })

    expect(fetch).not.toHaveBeenCalled()
    sendsAgain(form)

    // And the next submission is not swallowed.
    smartCaptcha.getResponse.mockReturnValue('second-try')
    await submit(form)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1))
  })

  it('SmartCaptcha: a network error of the check is reported', async () => {
    const smartCaptcha = fakeSmartCaptcha()
    window.smartCaptcha = smartCaptcha
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountProtectedForm()
    FetchIt.create(captchaConfig('smartcaptcha'))
    await vi.waitFor(() => expect(smartCaptcha.subscribe).toHaveBeenCalled())
    vi.stubGlobal('fetch', vi.fn())

    await submit(form)
    await vi.waitFor(() => expect(smartCaptcha.execute).toHaveBeenCalled())
    smartCaptcha.handlers.get('network-error')!()

    await vi.waitFor(() => expect(element(form, '[data-validation-error]').textContent).toBe('The check could not be completed'))
    sendsAgain(form)
  })

  it('warns when the server asks for a captcha the page does not have', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    const form = mountProtectedForm()
    FetchIt.create(config())
    answerSequence({ body: { success: false, message: 'Reload', data: [] }, headers: { 'X-FetchIt-Token': 'fresh', 'X-FetchIt-Refused': 'captcha' } })

    await submit(form)
    await vi.waitFor(() => expect(element(form, '[data-validation-error]').textContent).toBe('Reload'))

    expect(String(vi.mocked(console.warn).mock.calls[0]![0])).toContain('made before the captcha was turned on')
  })

  it('resets a reCAPTCHA v2 widget of the site after a success', async () => {
    window.grecaptcha = { reset: vi.fn() }
    const form = mountProtectedForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWithToken({ success: true, message: 'Sent', data: [] }, null))

    await submit(form)

    await vi.waitFor(() => expect(window.grecaptcha!.reset).toHaveBeenCalled())
  })

  it('a reCAPTCHA v2 widget that throws on reset does not spoil the success', async () => {
    window.grecaptcha = { reset: vi.fn(() => { throw new Error('no widget') }) }
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountProtectedForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWithToken({ success: true, message: 'Sent', data: [] }, null))
    field(form, 'email').value = 'ann@example.com'

    await submit(form)

    await vi.waitFor(() => expect(element(form, '[data-success]').textContent).toBe('Sent'))
    expect(field(form, 'email').value).toBe('')
  })
})
