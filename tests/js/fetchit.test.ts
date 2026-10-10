import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import '../../src/index'

// Node's Request, which the class uses, needs an absolute URL.
const actionUrl = 'http://localhost/assets/components/fetchit/action.php'
const action = '0123456789abcdef0123456789abcdef'

const FetchIt = window.FetchIt

type Config = ConstructorParameters<typeof FetchIt>[1]
type Handler = (event: CustomEvent) => void

// What the tests look at beyond the public types.
type Internals = {
  observer?: MutationObserver
  started?: typeof FetchIt
}
type InstanceInternals = FetchItInstance & {
  pending: boolean
  destroyed: boolean
  work?: { stop: AbortController }
  captcha: { destroy (): void } | null
}
const internals = FetchIt as typeof FetchIt & Internals
const instance = (form: HTMLFormElement) => FetchIt.instances.get(form) as InstanceInternals | undefined

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

// A form as the snippet outputs it: the config in data-fetchit-config.
function configuredForm(overrides: Partial<Config> = {}) {
  const host = document.createElement('div')
  host.innerHTML = `
    <form data-fetchit="${action}">
      <input name="email" value="ann@example.com">
      <button type="submit">Send</button>
      <div data-success style="display: none"></div>
      <div data-validation-error style="display: none"></div>
    </form>`
  const form = host.querySelector('form') as HTMLFormElement
  form.setAttribute('data-fetchit-config', JSON.stringify(config(overrides)))
  return { host, form }
}

// Let the MutationObserver deliver its records.
const settle = () => new Promise(resolve => setTimeout(resolve, 0))

describe('forms loaded after the page', () => {
  beforeEach(() => FetchIt.observe())

  it('init() takes the config from the form', () => {
    const { host, form } = configuredForm({ pageId: 42 })
    document.body.append(host)
    FetchIt.init(host)

    expect(FetchIt.instances.get(form)?.config.pageId).toBe(42)
  })

  it('init() takes the root itself when it is a form', () => {
    const { form } = configuredForm()
    FetchIt.init(form)

    expect(FetchIt.instances.get(form)).toBeInstanceOf(FetchIt)
  })

  it('init() leaves a form with a broken config to the browser', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { host, form } = configuredForm()
    form.setAttribute('data-fetchit-config', '{broken')
    FetchIt.init(host)

    expect(FetchIt.instances.has(form)).toBe(false)
    expect(console.error).toHaveBeenCalled()
  })

  it('init() leaves a config without an action', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { host, form } = configuredForm({ action: '' })
    FetchIt.init(host)

    expect(FetchIt.instances.has(form)).toBe(false)
  })

  it.each([
    ['an array', '[]'],
    ['null', 'null'],
    ['a string', '"abc"'],
    ['an action that is not a string', JSON.stringify({ ...config(), action: 0 })],
    ['no actionUrl', JSON.stringify({ ...config(), actionUrl: undefined })],
    ['no pageId', JSON.stringify({ ...config(), pageId: undefined })],
    ['the action of another form', JSON.stringify({ ...config(), action: 'other' })],
  ])('init() leaves a config that is %s', (_, value) => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { host, form } = configuredForm()
    form.setAttribute('data-fetchit-config', value)
    FetchIt.init(host)

    expect(FetchIt.instances.has(form)).toBe(false)
    expect(console.error).toHaveBeenCalledTimes(1)
  })

  it('init() starts the other forms when one cannot start', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const broken = configuredForm({ actionUrl: 'http://[bad' })
    const good = configuredForm()
    const host = document.createElement('div')
    host.append(broken.form, good.form)
    FetchIt.init(host)

    expect(FetchIt.instances.has(broken.form)).toBe(false)
    expect(FetchIt.instances.has(good.form)).toBe(true)
    expect(String(vi.mocked(console.error).mock.calls[0]![0])).toContain('sent without FetchIt')
  })

  it('init() of FetchIt makes instances of the class that observes', () => {
    class Forms extends FetchIt {}
    const started = internals.started
    internals.started = Forms
    try {
      const { host, form } = configuredForm()
      FetchIt.init(host)

      expect(FetchIt.instances.get(form)).toBeInstanceOf(Forms)
    } finally {
      internals.started = started
    }
  })

  it('a second instance of a form replaces the first', () => {
    const { host, form } = configuredForm()
    FetchIt.init(host)
    const first = instance(form)!
    const second = new FetchIt(form, config())

    expect(first.destroyed).toBe(true)
    expect(FetchIt.instances.get(form)).toBe(second)
    expect(FetchIt.forms.filter(item => item === form)).toHaveLength(1)
  })

  it('replaces a form swapped in with innerHTML', async () => {
    const { host, form } = configuredForm()
    document.body.append(host)
    await settle()
    const next = configuredForm()

    host.innerHTML = next.host.innerHTML
    await settle()
    const swapped = host.querySelector('form') as HTMLFormElement
    const fetch = respond({ success: true, message: 'Thanks', data: [] })
    await submit(swapped)

    expect(FetchIt.instances.has(form)).toBe(false)
    expect(FetchIt.instances.has(swapped)).toBe(true)
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('create() and observe() make one instance of a form', async () => {
    const { host, form } = configuredForm()
    document.body.append(host)
    FetchIt.create(config())
    FetchIt.observe()
    await settle()
    const fetch = respond({ success: true, message: 'Thanks', data: [] })
    await submit(form)

    expect(FetchIt.forms.filter(item => item === form)).toHaveLength(1)
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('picks up a form of a page cached by 4.0 put back later', async () => {
    const form = mountForm()
    FetchIt.create(config())
    const host = form.parentElement!
    form.remove()
    await settle()
    host.append(form)
    await settle()

    expect(FetchIt.instances.has(form)).toBe(true)
  })

  it('init() makes no second instance', () => {
    const { host, form } = configuredForm()
    FetchIt.init(host)
    const first = FetchIt.instances.get(form)
    FetchIt.init(host)

    expect(FetchIt.instances.get(form)).toBe(first)
    expect(FetchIt.forms.filter(item => item === form)).toHaveLength(1)
  })

  it('picks up a form inserted later and sends it', async () => {
    const { host, form } = configuredForm()
    document.body.append(host)
    await settle()
    const fetch = respond({ success: true, message: 'Thanks', data: [] })

    await submit(form)

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(element(form, '[data-success]').textContent).toBe('Thanks')
  })

  it('picks up a form inside an inserted element', async () => {
    const wrapper = document.createElement('section')
    const { host, form } = configuredForm()
    wrapper.append(host)
    document.body.append(wrapper)
    await settle()

    expect(FetchIt.instances.has(form)).toBe(true)
  })

  it('destroys a removed form', async () => {
    const { host, form } = configuredForm()
    document.body.append(host)
    await settle()

    host.remove()
    await settle()

    expect(FetchIt.instances.has(form)).toBe(false)
    expect(FetchIt.forms).not.toContain(form)
  })

  it('keeps a form moved within the page', async () => {
    const { host, form } = configuredForm()
    const other = document.createElement('div')
    document.body.append(host, other)
    await settle()
    const kept = FetchIt.instances.get(form)

    other.append(form)
    await settle()

    expect(FetchIt.instances.get(form)).toBe(kept)
  })

  it('keeps the other copy of the same form', async () => {
    const one = configuredForm()
    const two = configuredForm()
    document.body.append(one.host, two.host)
    await settle()

    one.host.remove()
    await settle()

    expect(FetchIt.instances.has(one.form)).toBe(false)
    expect(FetchIt.instances.has(two.form)).toBe(true)
  })

  it('destroying while solving stops the work quietly', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { host, form } = configuredForm({ pow: 24 })
    form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="fetchit_token" value="t">')
    document.body.append(host)
    await settle()
    const busy = instance(form)!
    form.dispatchEvent(new FocusEvent('focusin'))
    const stop = busy.work!.stop

    host.remove()
    await settle()

    expect(stop.signal.aborted).toBe(true)
    expect(busy.work).toBeUndefined()
    expect(console.error).not.toHaveBeenCalled()
    // The observer still works for the next form.
    const next = configuredForm()
    document.body.append(next.host)
    await settle()
    expect(FetchIt.instances.has(next.form)).toBe(true)
  })

  it('sends a form put back later only once', async () => {
    // A modal that detaches its content on close and appends it again.
    const { host, form } = configuredForm()
    document.body.append(host)
    await settle()
    host.remove()
    await settle()
    document.body.append(host)
    await settle()
    const fetch = respond({ success: true, message: 'Thanks', data: [] })

    await submit(form)

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(FetchIt.instances.has(form)).toBe(true)
  })

  it('sends a form put back later only once in a browser without the signal option', async () => {
    // Such a browser ignores the option: the listeners of the destroyed
    // instance stay on the form.
    const { host, form } = configuredForm()
    vi.spyOn(form, 'addEventListener').mockImplementation(function (this: HTMLFormElement, type: string, listener: EventListenerOrEventListenerObject | null, options?: boolean | AddEventListenerOptions) {
      const { signal: _signal, ...rest } = typeof options === 'object' ? options : {}
      EventTarget.prototype.addEventListener.call(this, type, listener, rest)
    } as typeof form.addEventListener)
    document.body.append(host)
    await settle()
    host.remove()
    await settle()
    document.body.append(host)
    await settle()
    const fetch = respond({ success: true, message: 'Thanks', data: [] })

    await submit(form)

    expect(vi.mocked(form.addEventListener).mock.calls.filter(([type]) => type === 'submit')).toHaveLength(2)
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('a form removed while solving for its submission ends quietly', async () => {
    const errors = vi.fn()
    on('fetchit:error', errors)
    FetchIt.Message = { error: vi.fn() }
    const { host, form } = configuredForm({ pow: 24 })
    form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="fetchit_token" value="t">')
    document.body.append(host)
    await settle()
    const busy = instance(form)!
    const fetch = respond({ success: true, message: 'Thanks', data: [] })

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    host.remove()
    await vi.waitFor(() => expect(busy.pending).toBe(false))

    expect(fetch).not.toHaveBeenCalled()
    expect(errors).not.toHaveBeenCalled()
    expect(FetchIt.Message.error).not.toHaveBeenCalled()
  })

  it('the answer to a removed form is handled and starts no new work', async () => {
    const { host, form } = configuredForm({ pow: 1 })
    form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="fetchit_token" value="t">')
    document.body.append(host)
    await settle()
    const busy = instance(form)!
    let answer: ((value: unknown) => void) | undefined
    const fetch = vi.fn(() => new Promise(resolve => { answer = resolve }))
    vi.stubGlobal('fetch', fetch)
    const success = vi.fn()
    on('fetchit:success', success)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())
    host.remove()
    await settle()
    answer!({
      headers: new Headers({ 'X-FetchIt-Token': 'next' }),
      json: async () => ({ success: true, message: 'Thanks', data: [] }),
    })
    await vi.waitFor(() => expect(busy.pending).toBe(false))

    expect(success).toHaveBeenCalled()
    expect(busy.work).toBeUndefined()
  })

  it('a form put back before the answer can be sent again', async () => {
    const { host, form } = configuredForm()
    document.body.append(host)
    await settle()
    const fetch = vi.fn(() => new Promise(() => {}))
    vi.stubGlobal('fetch', fetch)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    host.remove()
    await settle()
    document.body.append(host)
    await settle()
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle()

    expect(fetch).toHaveBeenCalledTimes(2)
  })

  it('a form put back while it is sent leaves its state to the new instance', async () => {
    const { host, form } = configuredForm()
    document.body.append(host)
    await settle()
    const answers: ((value: unknown) => void)[] = []
    vi.stubGlobal('fetch', vi.fn(() => new Promise(resolve => { answers.push(resolve) })))
    const accepted = { json: async () => ({ success: true, message: 'Thanks', data: [] }) }

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle()
    host.remove()
    await settle()
    document.body.append(host)
    await settle()

    // The new instance sends nothing yet.
    expect(form.getAttribute('aria-busy')).toBeNull()
    expect(form.getAttribute('data-fetchit-state')).toBeNull()

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle()
    answers[0]!(accepted)
    await settle()

    expect(form.getAttribute('data-fetchit-state')).toBe('sending')
    expect(form.getAttribute('aria-busy')).toBe('true')

    answers[1]!(accepted)
    await vi.waitFor(() => expect(form.getAttribute('data-fetchit-state')).toBe('success'))
    expect(form.getAttribute('aria-busy')).toBeNull()
  })

  it('destroy() twice does nothing more', async () => {
    const { host, form } = configuredForm()
    document.body.append(host)
    await settle()
    const destroyed = FetchIt.instances.get(form)!

    destroyed.destroy()
    expect(() => destroyed.destroy()).not.toThrow()
    expect(FetchIt.instances.has(form)).toBe(false)
  })

  it('observe() starts one observer', () => {
    const first = internals.observer
    FetchIt.observe()

    expect(first).toBeInstanceOf(MutationObserver)
    expect(internals.observer).toBe(first)
  })

  it('observe() turns the built-in notifier on', () => {
    FetchIt.observe({ defaultNotifier: true, notifierCloseLabel: 'Закрыть' })

    expect(FetchIt.Message?.success).toBeTypeOf('function')
  })

  it('create() still binds a form without the attribute', () => {
    // A page cached by FetchIt 4.0.
    const form = mountForm()
    FetchIt.create(config())

    expect(FetchIt.instances.get(form)).toBeInstanceOf(FetchIt)
    expect(internals.observer).toBeInstanceOf(MutationObserver)
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

describe('the state of the form', () => {
  const state = (form: HTMLFormElement) => form.getAttribute('data-fetchit-state')
  const busy = (form: HTMLFormElement) => form.getAttribute('aria-busy')

  // config() has neither formState nor ariaBusy, as on a page cached by an
  // earlier version: both are on.
  it('is sending and busy while the request is in flight', async () => {
    const form = mountForm()
    FetchIt.create(config())
    let during: (string | null)[] | undefined
    vi.stubGlobal('fetch', vi.fn(async () => {
      during = [state(form), busy(form)]
      return { json: async () => ({ success: true, message: '', data: [] }) }
    }))

    await submit(form)

    expect(during).toEqual(['sending', 'true'])
    expect(busy(form)).toBeNull()
  })

  it('is success after an accepted form, also when its fields are cleared', async () => {
    const form = mountForm()
    FetchIt.create(config({ clearFieldsOnSuccess: true }))
    respond({ success: true, message: 'Thanks', data: [] })

    await submit(form)

    expect(state(form)).toBe('success')
    expect(busy(form)).toBeNull()
  })

  it('is error after a refusal', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond({ success: false, message: 'Check the form', data: { email: 'Enter your email' } })

    await submit(form)

    expect(state(form)).toBe('error')
    expect(busy(form)).toBeNull()
  })

  it('is error after a failed request', async () => {
    const form = mountForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('Failed to fetch') }))
    vi.spyOn(console, 'error').mockImplementation(() => {})

    await submit(form)

    expect(state(form)).toBe('error')
    expect(busy(form)).toBeNull()
  })

  it('holds the outcome when a listener cancels fetchit:after', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond({ success: true, message: 'Thanks', data: [] })
    on('fetchit:after', event => event.preventDefault())

    await submit(form)

    expect(state(form)).toBe('success')
  })

  it('stays as it was when fetchit:before is cancelled', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond({ success: true, message: 'Thanks', data: [] })
    await submit(form)
    on('fetchit:before', event => event.preventDefault())

    await submit(form)

    expect(state(form)).toBe('success')
    expect(busy(form)).toBeNull()
  })

  it('is removed when the visitor resets the form', async () => {
    const form = mountForm()
    FetchIt.create(config({ clearFieldsOnSuccess: false }))
    respond({ success: true, message: 'Thanks', data: [] })
    await submit(form)

    form.reset()

    expect(state(form)).toBeNull()
  })

  it('keeps sending through a reset during the request', async () => {
    const form = mountForm()
    FetchIt.create(config())
    let during: (string | null)[] | undefined
    vi.stubGlobal('fetch', vi.fn(async () => {
      form.reset()
      during = [state(form), busy(form)]
      return { json: async () => ({ success: true, message: '', data: [] }) }
    }))

    await submit(form)

    expect(during).toEqual(['sending', 'true'])
    expect(state(form)).toBe('success')
  })

  it('is cleared when the form is destroyed during the request', async () => {
    const form = mountForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', vi.fn(async () => {
      instance(form)!.destroy()
      throw new TypeError('Failed to fetch')
    }))
    vi.spyOn(console, 'error').mockImplementation(() => {})

    await submit(form)

    expect(state(form)).toBeNull()
    expect(busy(form)).toBeNull()
  })

  it('is sending again on the next submission after an error', async () => {
    const form = mountForm()
    FetchIt.create(config())
    respond({ success: false, message: 'Check the form', data: {} })
    await submit(form)
    let during: (string | null)[] | undefined
    vi.stubGlobal('fetch', vi.fn(async () => {
      during = [state(form), busy(form)]
      return { json: async () => ({ success: true, message: '', data: [] }) }
    }))

    await submit(form)

    expect(during).toEqual(['sending', 'true'])
    expect(state(form)).toBe('success')
  })

  it('drops a state of sending the form brings from an old instance', () => {
    const form = mountForm()
    form.setAttribute('data-fetchit-state', 'sending')
    form.setAttribute('aria-busy', 'true')

    FetchIt.create(config())

    expect(state(form)).toBeNull()
    expect(busy(form)).toBeNull()
  })

  it('keeps the outcome the form brings from an old instance, as its message stays', () => {
    const form = mountForm()
    form.setAttribute('data-fetchit-state', 'success')

    FetchIt.create(config())

    expect(state(form)).toBe('success')
  })

  it('logs a setSubmissionState of a class of the site that throws, and the form still works', async () => {
    class Site extends FetchIt {
      setSubmissionState (): void {
        throw new Error('broken')
      }
    }
    const form = mountForm()
    form.setAttribute('aria-busy', 'true')
    new Site(form, config({ requestErrorMessage: 'Could not send' }))
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('Failed to fetch') }))
    const logged = vi.spyOn(console, 'error').mockImplementation(() => {})

    await submit(form)

    expect(logged).toHaveBeenCalledWith(expect.any(TypeError))
    expect(element(form, '[data-validation-error]').textContent).toBe('Could not send')
    expect(field(form, 'email').disabled).toBe(false)
    expect(instance(form)!.pending).toBe(false)
  })

  it('leaves out data-fetchit-state with formState off', async () => {
    const form = mountForm()
    FetchIt.create(config({ formState: false }))
    let during: (string | null)[] | undefined
    vi.stubGlobal('fetch', vi.fn(async () => {
      during = [state(form), busy(form)]
      return { json: async () => ({ success: true, message: '', data: [] }) }
    }))

    await submit(form)

    expect(during).toEqual([null, 'true'])
    expect(state(form)).toBeNull()
    expect(busy(form)).toBeNull()
  })

  it('leaves out aria-busy with ariaBusy off', async () => {
    const form = mountForm()
    FetchIt.create(config({ ariaBusy: false }))
    let during: (string | null)[] | undefined
    vi.stubGlobal('fetch', vi.fn(async () => {
      during = [state(form), busy(form)]
      return { json: async () => ({ success: true, message: '', data: [] }) }
    }))

    await submit(form)

    expect(during).toEqual(['sending', null])
    expect(state(form)).toBe('success')
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
    // The state is set before the event: it holds when the event is cancelled.
    expect(form.getAttribute('data-fetchit-state')).toBe('error')
    expect(form.hasAttribute('aria-busy')).toBe(false)
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
    expect(form.getAttribute('data-fetchit-state')).toBe('success')
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
      expect(form.getAttribute('data-fetchit-state')).toBe('error')
      expect(form.hasAttribute('aria-busy')).toBe(false)
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

describe('destroying a captcha', () => {
  afterEach(() => {
    delete window.turnstile
    delete window.smartCaptcha
    delete window.grecaptcha
  })

  it('removes the Turnstile widget', async () => {
    const turnstile = { ...fakeTurnstile(), remove: vi.fn() }
    window.turnstile = turnstile
    const form = mountForm()
    FetchIt.create(captchaConfig('turnstile'))
    await vi.waitFor(() => expect(turnstile.render).toHaveBeenCalled())

    instance(form)!.captcha!.destroy()

    expect(turnstile.remove).toHaveBeenCalledWith('widget-1')
  })

  it('renders no Turnstile widget when its script comes after', async () => {
    const form = mountForm()
    FetchIt.create(captchaConfig('turnstile'))
    instance(form)!.captcha!.destroy()

    const turnstile = fakeTurnstile()
    window.turnstile = turnstile
    await new Promise(resolve => setTimeout(resolve, 250))

    expect(turnstile.render).not.toHaveBeenCalled()
  })

  it('removes the SmartCaptcha widget', async () => {
    const smartCaptcha = { ...fakeSmartCaptcha(), destroy: vi.fn() }
    window.smartCaptcha = smartCaptcha
    const form = mountForm()
    FetchIt.create(captchaConfig('smartcaptcha'))
    await vi.waitFor(() => expect(smartCaptcha.render).toHaveBeenCalled())

    instance(form)!.captcha!.destroy()

    expect(smartCaptcha.destroy).toHaveBeenCalledWith(7)
  })

  it('renders no SmartCaptcha widget when its script comes after', async () => {
    const form = mountForm()
    FetchIt.create(captchaConfig('smartcaptcha'))
    instance(form)!.captcha!.destroy()

    const smartCaptcha = fakeSmartCaptcha()
    window.smartCaptcha = smartCaptcha
    await new Promise(resolve => setTimeout(resolve, 250))

    expect(smartCaptcha.render).not.toHaveBeenCalled()
  })

  it('ends a submission waiting for SmartCaptcha quietly', async () => {
    const smartCaptcha = fakeSmartCaptcha()
    window.smartCaptcha = smartCaptcha
    const form = mountForm()
    FetchIt.create(captchaConfig('smartcaptcha'))
    await vi.waitFor(() => expect(smartCaptcha.render).toHaveBeenCalled())
    const busy = instance(form)!
    const errors = vi.fn()
    on('fetchit:error', errors)
    const fetch = respond({ success: true, message: 'Thanks', data: [] })

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await vi.waitFor(() => expect(smartCaptcha.execute).toHaveBeenCalled())
    busy.destroy()
    await vi.waitFor(() => expect(busy.pending).toBe(false))

    expect(fetch).not.toHaveBeenCalled()
    expect(errors).not.toHaveBeenCalled()
    expect(form.hasAttribute('data-fetchit-state')).toBe(false)
    expect(form.hasAttribute('aria-busy')).toBe(false)
  })

  it('renders no Turnstile widget for a submission of a destroyed form', async () => {
    const form = mountForm()
    FetchIt.create(captchaConfig('turnstile'))
    // The observer has seen the form: it would start the form, still on
    // the page, again.
    await settle()
    const busy = instance(form)!
    const errors = vi.fn()
    on('fetchit:error', errors)
    const fetch = respond({ success: true, message: 'Thanks', data: [] })

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    busy.destroy()
    const turnstile = fakeTurnstile()
    window.turnstile = turnstile
    await vi.waitFor(() => expect(busy.pending).toBe(false))

    expect(turnstile.render).not.toHaveBeenCalled()
    expect(fetch).not.toHaveBeenCalled()
    expect(errors).not.toHaveBeenCalled()
  })

  it('takes the block of the widget out of the form', async () => {
    const turnstile = fakeTurnstile()
    window.turnstile = turnstile
    const form = mountForm()
    FetchIt.create(captchaConfig('turnstile'))
    await vi.waitFor(() => expect(turnstile.render).toHaveBeenCalled())

    instance(form)!.captcha!.destroy()

    expect(form.querySelector('.fetchit-captcha')).toBeNull()
  })

  it('copes with a provider that cannot remove widgets', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const turnstile = fakeTurnstile()
    window.turnstile = turnstile
    const form = mountForm()
    FetchIt.create(captchaConfig('turnstile'))
    await vi.waitFor(() => expect(turnstile.render).toHaveBeenCalled())

    expect(() => instance(form)!.captcha!.destroy()).not.toThrow()
    expect(console.error).not.toHaveBeenCalled()
  })

  it('leaves reCAPTCHA alone', () => {
    window.grecaptcha = { ready: vi.fn(), execute: vi.fn(), reset: vi.fn() }
    const form = mountForm()
    FetchIt.create(captchaConfig('recaptcha'))

    expect(() => instance(form)!.captcha!.destroy()).not.toThrow()
    expect(window.grecaptcha.reset).not.toHaveBeenCalled()
  })
})

// The bootstrap the plugin adds to a page, for the class name Forms
// (FetchItTest checks that it is the one the plugin outputs).
const bootstrap = readFileSync(`${process.cwd()}/tests/fixtures/bootstrap.html`, 'utf8')
  .replace(/^<script>|<\/script>\s*$/g, '')

function runBootstrap(forms: unknown) {
  let started: (() => void) | undefined
  vi.spyOn(window, 'addEventListener').mockImplementation(((type: string, listener: () => void) => {
    if (type === 'DOMContentLoaded') {
      started = listener
    }
  }) as typeof window.addEventListener)
  const page = window as unknown as { Forms?: unknown }
  page.Forms = forms
  try {
    new Function(bootstrap)()
    started!()
  } finally {
    delete page.Forms
  }
}

function mountForms(...actions: string[]) {
  document.body.innerHTML = actions.map(key => `<form data-fetchit="${key}"></form>`).join('')
  document.querySelectorAll('form').forEach((form, index) => {
    form.setAttribute('data-fetchit-config', JSON.stringify(config({ action: actions[index]! })))
  })
}

describe('the bootstrap of the page', () => {
  it('observes the page with the options of the page', () => {
    const observe = vi.fn()
    runBootstrap({ observe, create: vi.fn() })

    expect(observe).toHaveBeenCalledWith({ defaultNotifier: false, notifierCloseLabel: '' })
  })

  it('calls create() once per form key for a script without observe()', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    mountForms('a', 'a', 'b')
    document.body.insertAdjacentHTML('beforeend', '<form data-fetchit="c" data-fetchit-config="{broken"></form>')
    const create = vi.fn()
    runBootstrap({ create })

    expect(create.mock.calls.map(([options]) => options.action)).toEqual(['a', 'b'])
    expect(console.error).toHaveBeenCalledTimes(1)
    expect(String(vi.mocked(console.error).mock.calls[0]![0])).toContain('sent without FetchIt')
  })

  it('calls create() of a class of the site, then observes', () => {
    mountForms('a')
    const calls: string[] = []
    class Forms extends FetchIt {
      static override create (_config: Parameters<typeof FetchIt.create>[0]) { calls.push('create') }
      static override observe (_options?: Parameters<typeof FetchIt.observe>[0]) { calls.push('observe') }
    }
    runBootstrap(Forms)

    expect(calls).toEqual(['create', 'observe'])
  })

  it('only observes for a class of the site that keeps create()', () => {
    mountForms('a')
    const calls: string[] = []
    class Forms extends FetchIt {
      static override observe (_options?: Parameters<typeof FetchIt.observe>[0]) { calls.push('observe') }
    }
    runBootstrap(Forms)

    expect(calls).toEqual(['observe'])
  })

  it('tells what to check when the class is missing', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    runBootstrap(undefined)

    expect(String(vi.mocked(console.error).mock.calls[0]![0])).toContain('fetchit.frontend.js.classname')
  })
})

type Exchange = (init: RequestInit) => Promise<Record<string, string>>

const submitted: string[] = []

/**
 * A server for a page from a full-page cache: exchanges answer with
 * `exchange`, submissions with the answers in turn.
 */
function cachedPageServer(exchange: Exchange, ...answers: { body: unknown, headers: Record<string, string> }[]) {
  submitted.length = 0
  const fetch = vi.fn(async (_input: RequestInfo, init?: RequestInit) => {
    if (new Headers(init?.headers).get('X-FetchIt-Exchange') === '1') {
      const headers = await exchange(init!)
      return { status: 204, headers: new Headers(headers), json: async () => { throw new SyntaxError('No body') } }
    }
    // The script reuses one FormData for the retry: remember what each submission carried.
    submitted.push(String((init!.body as FormData).get('fetchit_token')))
    const answer = answers.shift() ?? { body: { success: true, message: 'Sent', data: [] }, headers: {} }
    return { headers: new Headers(answer.headers), json: async () => answer.body }
  })
  vi.stubGlobal('fetch', fetch)
  return fetch
}

const isExchange = ([, init]: unknown[]) => new Headers((init as RequestInit | undefined)?.headers).get('X-FetchIt-Exchange') === '1'
const exchangesOf = (fetch: ReturnType<typeof vi.fn>) => fetch.mock.calls.filter(isExchange)
const submissionsOf = (fetch: ReturnType<typeof vi.fn>) => fetch.mock.calls.filter(call => !isExchange(call))
const sentToken = (call: unknown[]) => ((call[1] as RequestInit).body as FormData).get('fetchit_token')

function focus(form: HTMLFormElement) {
  field(form, 'email').dispatchEvent(new FocusEvent('focusin', { bubbles: true }))
}

describe('a page from a full-page cache', () => {
  it('exchanges the token of the page once, on the first focus', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    const fetch = cachedPageServer(async () => ({ 'X-FetchIt-Token': 'fresh' }))

    focus(form)
    focus(form)
    await settle()

    const exchanges = exchangesOf(fetch)
    expect(exchanges).toHaveLength(1)
    expect(sentToken(exchanges[0]!)).toBe('old-token')
    expect(new Headers((exchanges[0]![1] as RequestInit).headers).get('X-FetchIt-Action')).toBe(action)
    expect((exchanges[0]![1] as RequestInit).method).toBe('post')
    expect(field(form, 'fetchit_token').value).toBe('fresh')
  })

  it('logs an exchange that fails on the first focus instead of leaving it unhandled', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    cachedPageServer(async () => ({ 'X-FetchIt-Token': 'fresh' }))
    const failure = new Error('broken')
    vi.spyOn(FetchIt.prototype, 'exchangeToken').mockRejectedValueOnce(failure)
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})

    focus(form)
    await settle()

    expect(error).toHaveBeenCalledWith(failure)
  })

  it('sends the page with the exchange, for the settings of its context', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    const fetch = cachedPageServer(async () => ({ 'X-FetchIt-Token': 'fresh' }))

    focus(form)
    await settle()

    const body = (exchangesOf(fetch)[0]![1] as RequestInit).body as FormData
    expect(body.get('pageId')).toBe('5')
  })

  it('sends the fresh token, once', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    const fetch = cachedPageServer(async () => ({ 'X-FetchIt-Token': 'fresh' }))

    focus(form)
    await settle()
    await submit(form)

    const submissions = submissionsOf(fetch)
    expect(submissions).toHaveLength(1)
    expect(sentToken(submissions[0]!)).toBe('fresh')
    expect(element(form, '[data-success]').textContent).toBe('Sent')
  })

  it('waits for the exchange when sent before its answer', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    let answer!: (headers: Record<string, string>) => void
    const fetch = cachedPageServer(() => new Promise(resolve => { answer = resolve }))

    focus(form)
    await submit(form)
    expect(submissionsOf(fetch)).toHaveLength(0)

    answer({ 'X-FetchIt-Token': 'fresh' })
    await vi.waitFor(() => expect(submissionsOf(fetch)).toHaveLength(1))
    expect(sentToken(submissionsOf(fetch)[0]!)).toBe('fresh')
    expect(exchangesOf(fetch)).toHaveLength(1)
  })

  it('exchanges first when sent without a focus', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    const fetch = cachedPageServer(async () => ({ 'X-FetchIt-Token': 'fresh' }))

    await submit(form)

    await vi.waitFor(() => expect(submissionsOf(fetch)).toHaveLength(1))
    expect(exchangesOf(fetch)).toHaveLength(1)
    expect(sentToken(submissionsOf(fetch)[0]!)).toBe('fresh')
  })

  it('keeps the token of the page and the retry when the exchange fails', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const fetch = cachedPageServer(
      async () => { throw new TypeError('Failed to fetch') },
      { body: { success: false, message: 'Expired', data: [] }, headers: { 'X-FetchIt-Token': 'retry', 'X-FetchIt-Refused': 'token' } },
      { body: { success: true, message: 'Sent', data: [] }, headers: {} },
    )

    focus(form)
    await settle()
    await submit(form)
    await vi.waitFor(() => expect(submissionsOf(fetch)).toHaveLength(2))

    expect(submitted).toEqual(['old-token', 'retry'])
    expect(warn).toHaveBeenCalled()
    expect(element(form, '[data-success]').textContent).toBe('Sent')
  })

  it('waits for the fill time of the server before the retry', async () => {
    // The next token of a used or expired one is timed now.
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    const fetch = cachedPageServer(
      async () => ({ 'X-FetchIt-Refused': 'rate' }),
      { body: { success: false, message: 'Expired', data: [] }, headers: { 'X-FetchIt-Token': 'retry', 'X-FetchIt-Refused': 'token', 'X-FetchIt-Min-Time': '1' } },
      { body: { success: true, message: 'Sent', data: [] }, headers: {} },
    )
    focus(form)
    await settle()

    await submit(form)
    await vi.waitFor(() => expect(submissionsOf(fetch)).toHaveLength(1))
    const refused = Date.now()

    await vi.waitFor(() => expect(submissionsOf(fetch)).toHaveLength(2), { timeout: 3000 })
    expect(Date.now() - refused).toBeGreaterThanOrEqual(1000)
    expect(submitted).toEqual(['old-token', 'retry'])
    await vi.waitFor(() => expect(element(form, '[data-success]').textContent).toBe('Sent'))
  })

  it('sends no retry from a form destroyed while it waits for the fill time', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    const fetch = cachedPageServer(
      async () => ({ 'X-FetchIt-Refused': 'rate' }),
      { body: { success: false, message: 'Expired', data: [] }, headers: { 'X-FetchIt-Token': 'retry', 'X-FetchIt-Refused': 'token', 'X-FetchIt-Min-Time': '1' } },
    )
    focus(form)
    await settle()

    await submit(form)
    await vi.waitFor(() => expect(submissionsOf(fetch)).toHaveLength(1))
    instance(form)!.destroy()
    await new Promise(resolve => setTimeout(resolve, 1400))

    expect(submissionsOf(fetch)).toHaveLength(1)
    expect(element(form, '[data-validation-error]').textContent).toBe('')
    expect(error).not.toHaveBeenCalled()
    expect(form.hasAttribute('data-fetchit-state')).toBe(false)
    expect(form.hasAttribute('aria-busy')).toBe(false)
  })

  it('keeps the token of the page when the server gives none', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    cachedPageServer(async () => ({ 'X-FetchIt-Refused': 'rate' }))

    focus(form)
    await settle()

    expect(field(form, 'fetchit_token').value).toBe('old-token')
  })

  it('solves the proof of work for the fresh token, at the difficulty of the server', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true, pow: 0 }))
    const fetch = cachedPageServer(async () => ({ 'X-FetchIt-Token': 'fresh', 'X-FetchIt-Pow': '8' }))

    focus(form)
    await settle()
    await submit(form)
    await vi.waitFor(() => expect(submissionsOf(fetch)).toHaveLength(1))

    const body = (submissionsOf(fetch)[0]![1] as RequestInit).body as FormData
    expect(zeroBitsOf(`fresh:${body.get('fetchit_pow')}`)).toBeGreaterThanOrEqual(8)
  })

  it('waits for the fill time of the server before sending', async () => {
    // Autofill and Send right away would be "too fast" for a token of now.
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    const fetch = cachedPageServer(async () => ({ 'X-FetchIt-Token': 'fresh', 'X-FetchIt-Min-Time': '1' }))
    focus(form)
    await settle()
    const started = Date.now()

    await submit(form)
    expect(submissionsOf(fetch)).toHaveLength(0)

    await vi.waitFor(() => expect(submissionsOf(fetch)).toHaveLength(1), { timeout: 3000 })
    expect(Date.now() - started).toBeGreaterThanOrEqual(1000)
  })

  it('stops the exchange quietly when the form is destroyed', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    let signal: AbortSignal | undefined
    cachedPageServer(init => new Promise((_resolve, reject) => {
      signal = init.signal ?? undefined
      signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
    }))

    focus(form)
    await settle()
    instance(form)!.destroy()
    await settle()

    expect(signal?.aborted).toBe(true)
    expect(warn).not.toHaveBeenCalled()
  })

  it('sends nothing from a form destroyed while its token is exchanged', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ freshToken: true }))
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    const fetch = cachedPageServer(init => new Promise((_resolve, reject) => {
      init.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
    }))

    focus(form)
    await submit(form)
    instance(form)!.destroy()
    await settle()
    await settle()

    expect(submissionsOf(fetch)).toHaveLength(0)
    expect(element(form, '[data-validation-error]').textContent).toBe('')
    expect(warn).not.toHaveBeenCalled()
    expect(error).not.toHaveBeenCalled()
    expect(form.hasAttribute('data-fetchit-state')).toBe(false)
    expect(form.hasAttribute('aria-busy')).toBe(false)
  })

  it('exchanges nothing without freshToken', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config())
    const fetch = cachedPageServer(async () => ({ 'X-FetchIt-Token': 'fresh' }))

    focus(form)
    await settle()
    await submit(form)

    expect(exchangesOf(fetch)).toHaveLength(0)
    expect(sentToken(submissionsOf(fetch)[0]!)).toBe('old-token')
  })
})

describe('redirect after a success', () => {
  type Navigating = { navigate (url: string): boolean }
  const navigate = () => vi.spyOn(FetchIt.prototype as unknown as Navigating, 'navigate')

  it('goes to the redirect of the answer after the success', async () => {
    const leave = navigate().mockReturnValue(true)
    const form = mountForm()
    FetchIt.create(config())
    const events: string[] = []
    on('fetchit:success', () => events.push('success'))
    on('fetchit:reset', () => events.push('reset'))
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [], redirect: 'https://example.com/thanks/' }))

    await submit(form)

    expect(leave).toHaveBeenCalledWith('https://example.com/thanks/')
    expect(events).toEqual(['success', 'reset'])
    expect(element(form, '[data-success]').textContent).toBe('Thanks')
    expect(form.getAttribute('data-fetchit-state')).toBe('success')
  })

  it('keeps the fields disabled while it leaves', async () => {
    navigate().mockReturnValue(true)
    const form = mountForm()
    FetchIt.create(config())
    const fetch = answerWith({ success: true, message: 'Thanks', data: [], redirect: 'https://example.com/thanks/' })
    vi.stubGlobal('fetch', fetch)

    await submit(form)
    await submit(form)

    expect(field(form, 'email').disabled).toBe(true)
    expect(form.querySelector('button')!.disabled).toBe(true)
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('gives the redirect to fetchit:success, and stays when it is cancelled', async () => {
    const leave = navigate()
    const form = mountForm()
    FetchIt.create(config())
    let redirect: string | undefined
    on('fetchit:success', event => {
      redirect = (event.detail as FetchItSuccessDetail).redirect
      event.preventDefault()
    })
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [], redirect: '/thanks/' }))

    await submit(form)

    expect(redirect).toBe('/thanks/')
    expect(leave).not.toHaveBeenCalled()
    expect(field(form, 'email').disabled).toBe(false)
  })

  it('stays on an answer without a redirect, or with one that is not a string', async () => {
    const leave = navigate()
    const form = mountForm()
    FetchIt.create(config())
    let detail: FetchItSuccessDetail | undefined
    on('fetchit:success', event => { detail = event.detail as FetchItSuccessDetail })
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [], redirect: 5 }))

    await submit(form)

    expect(leave).not.toHaveBeenCalled()
    expect(detail).not.toHaveProperty('redirect')
    expect(detail!.response).toEqual({ success: true, message: 'Thanks', data: [] })
    expect(field(form, 'email').disabled).toBe(false)
  })

  it('stays on an error that has a redirect', async () => {
    const leave = navigate()
    const form = mountForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWith({ success: false, message: 'Check', data: [], redirect: '/thanks/' }))

    await submit(form)

    expect(leave).not.toHaveBeenCalled()
  })

  it('does not go to an address that is not http(s)', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [], redirect: 'javascript:alert(1)' }))

    await submit(form)

    expect(error).toHaveBeenCalledWith(expect.stringContaining('javascript:alert(1)'))
    expect(field(form, 'email').disabled).toBe(false)
    expect(instance(form)!.pending).toBe(false)
  })

  it('gives the form back when the page comes back from the bfcache', async () => {
    navigate().mockReturnValue(true)
    const form = mountForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [], redirect: '/thanks/' }))
    await submit(form)

    window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: false }))
    expect(field(form, 'email').disabled).toBe(true)

    window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }))
    expect(field(form, 'email').disabled).toBe(false)
    expect(instance(form)!.pending).toBe(false)
    expect(form.getAttribute('data-fetchit-state')).toBe('success')
  })

  it('does not lock the form when navigate() of the site throws', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    navigate().mockImplementation(() => { throw new Error('x') })
    const form = mountForm()
    FetchIt.create(config())
    vi.stubGlobal('fetch', answerWith({ success: true, message: 'Thanks', data: [], redirect: '/thanks/' }))

    await submit(form)

    expect(field(form, 'email').disabled).toBe(false)
    expect(instance(form)!.pending).toBe(false)
    expect(error).toHaveBeenCalledWith(expect.stringContaining('navigate() threw'), expect.any(Error))
  })

  it('navigate() does not leave the page for a hash of the same page', () => {
    const form = mountForm()
    FetchIt.create(config())
    const fetchit = instance(form) as unknown as Navigating

    expect(fetchit.navigate('#done')).toBe(false)
    expect(fetchit.navigate(`${window.location.href.split('#')[0]}#x`)).toBe(false)
    expect(window.location.hash).toBe('#x')
    window.location.hash = ''
  })

  it('navigate() turns down what is not an http(s) address', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountForm()
    FetchIt.create(config())
    const fetchit = instance(form) as unknown as Navigating

    expect(fetchit.navigate('javascript:alert(1)')).toBe(false)
    expect(fetchit.navigate('data:text/html,hi')).toBe(false)
    expect(fetchit.navigate('http://[bad')).toBe(false)
  })
})

describe('timeout and retries', () => {
  type Step = Error | 'hang' | { status?: number, body?: unknown, headers?: Record<string, string> }
  const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
  const sent = { success: true, message: 'Sent', data: [] }

  function server(...steps: Step[]) {
    const fetch = vi.fn((_request: Request, init?: RequestInit) => {
      const step = steps.shift() ?? { body: sent }
      if (step instanceof Error) {
        return Promise.reject(step)
      }
      if (step === 'hang') {
        return new Promise((_resolve, reject) => {
          init?.signal?.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')))
        })
      }
      return Promise.resolve({
        status: step.status ?? 200,
        url: actionUrl,
        headers: new Headers(step.headers ?? {}),
        json: async () => {
          if (typeof step.body === 'string') {
            throw new SyntaxError('Unexpected token <')
          }
          return step.body
        },
      })
    })
    vi.stubGlobal('fetch', fetch)
    return fetch
  }

  const keyOf = (call: unknown[]) => new Headers((call[1] as RequestInit | undefined)?.headers).get('X-FetchIt-Submission')
  const tokenOf = (call: unknown[]) => ((call[1] as RequestInit).body as FormData).get('fetchit_token')

  async function send(form: HTMLFormElement) {
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await vi.advanceTimersByTimeAsync(0)
  }

  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('sends no submission key without retries, as before', async () => {
    const form = mountForm()
    FetchIt.create(config())
    const fetch = server({ body: sent })

    await send(form)

    expect(fetch.mock.calls[0][1]).toEqual({ method: 'post', body: expect.any(FormData) })
    expect(keyOf(fetch.mock.calls[0])).toBeNull()
  })

  it('retries a network error with the same key after a pause', async () => {
    const form = mountForm()
    FetchIt.create(config({ retries: 2 }))
    const fetch = server(new TypeError('Failed to fetch'), { body: sent })

    await send(form)
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(form.getAttribute('data-fetchit-state')).toBe('sending')
    expect(field(form, 'email').disabled).toBe(true)
    await vi.advanceTimersByTimeAsync(999)
    expect(fetch).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(keyOf(fetch.mock.calls[0])).toMatch(uuid)
    expect(keyOf(fetch.mock.calls[1])).toBe(keyOf(fetch.mock.calls[0]))
    expect(new Headers((fetch.mock.calls[0][1] as RequestInit).headers).get('X-FetchIt-Action')).toBe(action)
    expect(element(form, '[data-success]').textContent).toBe('Sent')
  })

  it('retries 502, 503 and 504 that are not FetchIt answers, pausing 1, 2 and 4 s', async () => {
    const form = mountForm()
    FetchIt.create(config({ retries: 3 }))
    const fetch = server({ status: 502, body: '<html>' }, { status: 503, body: '<html>' }, { status: 504, body: { error: 'gateway' } }, { body: sent })

    await send(form)
    await vi.advanceTimersByTimeAsync(1000)
    expect(fetch).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(2000)
    expect(fetch).toHaveBeenCalledTimes(3)
    await vi.advanceTimersByTimeAsync(3999)
    expect(fetch).toHaveBeenCalledTimes(3)
    await vi.advanceTimersByTimeAsync(1)

    expect(fetch).toHaveBeenCalledTimes(4)
    expect(element(form, '[data-success]').textContent).toBe('Sent')
  })

  it('waits while the first attempt is still processed, with the next token', async () => {
    const form = mountProtectedForm()
    FetchIt.create(config({ retries: 1 }))
    const fetch = server(
      { status: 409, body: { success: false, message: 'Still', data: [] }, headers: { 'X-FetchIt-Refused': 'pending', 'X-FetchIt-Token': 'next' } },
      { body: sent },
    )

    await send(form)
    // The attempts share one FormData: its token is read before the retry sets the next.
    expect(tokenOf(fetch.mock.calls[0])).toBe('old-token')
    await vi.advanceTimersByTimeAsync(1000)

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(tokenOf(fetch.mock.calls[1])).toBe('next')
    expect(element(form, '[data-success]').textContent).toBe('Sent')
  })

  it('shows the pending answer when no retry is left', async () => {
    const form = mountForm()
    FetchIt.create(config({ retries: 1 }))
    const pending = { status: 409, body: { success: false, message: 'Still', data: [] }, headers: { 'X-FetchIt-Refused': 'pending' } }
    server(pending, pending)

    await send(form)
    await vi.advanceTimersByTimeAsync(1000)

    expect(element(form, '[data-validation-error]').textContent).toBe('Still')
    expect(form.getAttribute('data-fetchit-state')).toBe('error')
  })

  it('does not retry a FetchIt answer, nor an HTML 500', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountForm()
    FetchIt.create(config({ retries: 2, requestErrorMessage: 'Could not send' }))
    const fetch = server({ status: 500, body: '<html>' }, { body: { success: false, message: 'Check', data: [] } })

    await send(form)
    await vi.advanceTimersByTimeAsync(10000)
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(element(form, '[data-validation-error]').textContent).toBe('Could not send')

    await send(form)
    await vi.advanceTimersByTimeAsync(10000)
    expect(fetch).toHaveBeenCalledTimes(2)
    expect(element(form, '[data-validation-error]').textContent).toBe('Check')
  })

  it('gives up after the retries with the error of the last attempt', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountForm()
    FetchIt.create(config({ retries: 1, requestErrorMessage: 'Could not send' }))
    const last = new TypeError('Failed to fetch again')
    const errors: unknown[] = []
    on('fetchit:error', event => errors.push((event.detail as FetchItErrorDetail).error))
    const fetch = server(new TypeError('Failed to fetch'), last)

    await send(form)
    await vi.advanceTimersByTimeAsync(1000)

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(errors).toEqual([last])
    expect(element(form, '[data-validation-error]').textContent).toBe('Could not send')
    expect(field(form, 'email').disabled).toBe(false)
  })

  it('stops an attempt after the timeout and says the form may have been sent', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountForm()
    FetchIt.create(config({ timeout: 5, timeoutMessage: 'No answer in time' }))
    const fetch = server('hang')

    await send(form)
    expect((fetch.mock.calls[0][1] as RequestInit).signal).toBeInstanceOf(AbortSignal)
    await vi.advanceTimersByTimeAsync(4999)
    expect(element(form, '[data-validation-error]').textContent).toBe('')
    await vi.advanceTimersByTimeAsync(1)

    expect(element(form, '[data-validation-error]').textContent).toBe('No answer in time')
    expect(keyOf(fetch.mock.calls[0])).toBeNull()
  })

  it('retries after a timeout', async () => {
    const form = mountForm()
    FetchIt.create(config({ timeout: 5, retries: 1 }))
    const fetch = server('hang', { body: sent })

    await send(form)
    await vi.advanceTimersByTimeAsync(5000 + 1000)

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(element(form, '[data-success]').textContent).toBe('Sent')
  })

  it('tells each retry in fetchit:retry, which can stop them', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountForm()
    FetchIt.create(config({ retries: 3, requestErrorMessage: 'Could not send' }))
    const failure = new TypeError('Failed to fetch')
    const retries: FetchItRetryDetail[] = []
    on('fetchit:retry', event => {
      retries.push(event.detail as FetchItRetryDetail)
      if (retries.length === 2) {
        event.preventDefault()
      }
    })
    const fetch = server(failure, failure, failure)

    await send(form)
    await vi.advanceTimersByTimeAsync(1000)
    await vi.advanceTimersByTimeAsync(10000)

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(retries.map(retry => retry.attempt)).toEqual([1, 2])
    expect(retries[0]).toMatchObject({ form, error: failure })
    expect(element(form, '[data-validation-error]').textContent).toBe('Could not send')
  })

  it('sends nothing more from a form destroyed during the pause', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    const form = mountForm()
    FetchIt.create(config({ retries: 2 }))
    const fetch = server(new TypeError('Failed to fetch'))

    await send(form)
    instance(form)!.destroy()
    await vi.advanceTimersByTimeAsync(10000)

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(element(form, '[data-validation-error]').textContent).toBe('')
    expect(error).not.toHaveBeenCalled()
  })

  it('gives every submission a key of its own', async () => {
    const form = mountForm()
    FetchIt.create(config({ retries: 1 }))
    const fetch = server({ body: sent }, { body: sent })

    await send(form)
    await send(form)

    expect(keyOf(fetch.mock.calls[0])).toMatch(uuid)
    expect(keyOf(fetch.mock.calls[1])).toMatch(uuid)
    expect(keyOf(fetch.mock.calls[1])).not.toBe(keyOf(fetch.mock.calls[0]))
  })

  it('makes a key without crypto.randomUUID (a page over http)', async () => {
    const getRandomValues = globalThis.crypto.getRandomValues.bind(globalThis.crypto)
    vi.stubGlobal('crypto', { getRandomValues })
    const form = mountForm()
    FetchIt.create(config({ retries: 1 }))
    const fetch = server({ body: sent })

    await send(form)

    expect(keyOf(fetch.mock.calls[0])).toMatch(uuid)
  })
})
