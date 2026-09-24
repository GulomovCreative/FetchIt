import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

  it('rejects anything but a form', () => {
    expect(() => new FetchIt(document.createElement('div'), config())).toThrow()
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

  it('keeps the fields when clearFieldsOnSuccess is off', async () => {
    const form = mountForm()
    FetchIt.create(config({ clearFieldsOnSuccess: false }))
    respond(success)
    field(form, 'email').value = 'ann@example.com'

    await submit(form)

    expect(field(form, 'email').value).toBe('ann@example.com')
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

  it('tells the visitor when handling the answer throws', async () => {
    const form = mountForm()
    FetchIt.create(config({ requestErrorMessage }))
    respond({ success: false, message: 'Errors', data: { email: 'Required' } })
    vi.spyOn(console, 'error').mockImplementation(() => {})
    FetchIt.Message = { error: vi.fn(), after: () => { throw new Error('broken notifier') } }

    await submit(form)

    expect(FetchIt.Message.error).toHaveBeenCalledWith(requestErrorMessage)
    expect(field(form, 'email').disabled).toBe(false)
  })
})
