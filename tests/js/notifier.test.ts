import { afterEach, describe, expect, it, vi } from 'vitest'
import { createNotifier } from '../../src/notifier'

function toasts() {
  return Array.from(document.querySelectorAll<HTMLElement>('.fetchit-toasts .fetchit-toast'))
}

function live(role: 'status' | 'alert') {
  return document.querySelector<HTMLElement>(`.fetchit-toasts-live[role="${role}"]`)
}

function closeButton(toast: HTMLElement) {
  return toast.querySelector<HTMLButtonElement>('.fetchit-toast__close')!
}

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
  document.getElementById('fetchit-toasts-style')?.remove()
})

describe('the built-in notifier', () => {
  it('shows a success and an error', () => {
    const notifier = createNotifier()

    notifier.success('Sent')
    notifier.error('Check the form')

    const [success, error] = toasts()
    expect(success!.dataset.type).toBe('success')
    expect(success!.querySelector('.fetchit-toast__text')!.textContent).toBe('Sent')
    expect(error!.dataset.type).toBe('error')
  })

  it('has its live regions before the first message', () => {
    createNotifier()

    expect(live('status')?.textContent).toBe('')
    expect(live('alert')?.textContent).toBe('')
  })

  it('announces a success politely and an error at once, without the button', () => {
    vi.useFakeTimers()
    const notifier = createNotifier({ closeLabel: 'Close' })

    notifier.success('Sent')
    notifier.error('Check the form')
    vi.advanceTimersByTime(100)

    expect(live('status')!.textContent).toBe('Sent')
    expect(live('alert')!.textContent).toBe('Check the form')
    // The toasts themselves are not live: the regions say it once.
    expect(toasts().every(toast => !toast.hasAttribute('role') && !toast.hasAttribute('aria-live'))).toBe(true)
  })

  it('announces the same message twice', () => {
    vi.useFakeTimers()
    const notifier = createNotifier()

    notifier.error('Failed')
    vi.advanceTimersByTime(100)
    notifier.error('Failed')

    expect(live('alert')!.textContent).toBe('')
    vi.advanceTimersByTime(100)
    expect(live('alert')!.textContent).toBe('Failed')
  })

  it('shows the message as text', () => {
    createNotifier().success('<img src=x onerror=alert(1)>Tom &amp; <b>Jerry</b>')

    const text = toasts()[0]!.querySelector('.fetchit-toast__text')!
    expect(text.textContent).toBe('Tom &amp; Jerry')
    expect(text.children).toHaveLength(0)
  })

  it('shows nothing for an empty message', () => {
    const notifier = createNotifier()

    notifier.error(' <br> ')
    notifier.success(undefined as unknown as string)
    notifier.error(null as unknown as string)

    expect(toasts()).toHaveLength(0)
  })

  it('adds its styles once, before those of the site', () => {
    document.head.innerHTML = '<link rel="stylesheet" href="/site.css">'
    const notifier = createNotifier()

    notifier.success('One')
    notifier.success('Two')

    const styles = document.head.querySelectorAll('#fetchit-toasts-style')
    expect(styles).toHaveLength(1)
    expect(document.head.firstElementChild).toBe(styles[0])
    // One class: stronger than button { ... } of the site, weaker than its classes.
    expect(styles[0]!.textContent).toContain('\n.fetchit-toast__close {')
    // green-700 and red-600 of Tailwind CSS 4, with hex for browsers without oklch().
    expect(styles[0]!.textContent).toContain('var(--fetchit-toast-success, #008236)')
    expect(styles[0]!.textContent).toContain('var(--fetchit-toast-success, oklch(52.7% 0.154 150.069))')
    expect(styles[0]!.textContent).toContain('var(--fetchit-toast-error, oklch(57.7% 0.245 27.325))')
  })

  it('says so when a Content-Security-Policy blocks its styles', () => {
    vi.spyOn(HTMLStyleElement.prototype, 'sheet', 'get').mockReturnValue(null)
    vi.spyOn(console, 'warn').mockImplementation(() => {})

    createNotifier().success('Sent')

    expect(String(vi.mocked(console.warn).mock.calls[0]![0])).toContain('Content-Security-Policy')
  })

  it('closes with its button, labelled "Close" by default', () => {
    createNotifier({ closeLabel: '' }).success('Sent')
    const close = closeButton(toasts()[0]!)

    expect(close.type).toBe('button')
    expect(close.getAttribute('aria-label')).toBe('Close')
    close.click()

    expect(toasts()).toHaveLength(0)
  })

  it('takes a label for its button', () => {
    createNotifier({ closeLabel: 'Закрыть' }).success('Sent')

    expect(closeButton(toasts()[0]!).getAttribute('aria-label')).toBe('Закрыть')
  })

  it('closes by itself after 6 seconds', () => {
    vi.useFakeTimers()
    createNotifier().success('Sent')

    vi.advanceTimersByTime(5999)
    expect(toasts()).toHaveLength(1)
    vi.advanceTimersByTime(1)
    expect(toasts()).toHaveLength(0)
  })

  it('stays while the pointer is on it and starts over after', () => {
    vi.useFakeTimers()
    createNotifier({ duration: 1000 }).success('Sent')
    const toast = toasts()[0]!

    toast.dispatchEvent(new Event('mouseenter'))
    vi.advanceTimersByTime(5000)
    expect(toasts()).toHaveLength(1)

    toast.dispatchEvent(new Event('mouseleave'))
    vi.advanceTimersByTime(999)
    expect(toasts()).toHaveLength(1)
    vi.advanceTimersByTime(1)
    expect(toasts()).toHaveLength(0)
  })

  it('stays while the focus is on it, even when the pointer leaves', () => {
    vi.useFakeTimers()
    createNotifier({ duration: 1000 }).error('Failed')
    const toast = toasts()[0]!

    closeButton(toast).focus()
    toast.dispatchEvent(new Event('mouseenter'))
    toast.dispatchEvent(new Event('mouseleave'))
    vi.advanceTimersByTime(5000)
    expect(toasts()).toHaveLength(1)

    closeButton(toast).blur()
    vi.advanceTimersByTime(1000)
    expect(toasts()).toHaveLength(0)
  })

  it.each([0, Infinity])('stays until closed with duration %s', value => {
    vi.useFakeTimers()
    createNotifier({ duration: value }).success('Sent')

    vi.advanceTimersByTime(10 ** 9)

    expect(toasts()).toHaveLength(1)
  })

  it.each([Number.NaN, -1])('warns about duration %s and uses 6 seconds', value => {
    vi.useFakeTimers()
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    createNotifier({ duration: value }).success('Sent')

    expect(console.warn).toHaveBeenCalled()
    vi.advanceTimersByTime(5999)
    expect(toasts()).toHaveLength(1)
    vi.advanceTimersByTime(1)
    expect(toasts()).toHaveLength(0)
  })

  it('takes a duration longer than setTimeout allows', () => {
    // 2^31 ms and more would fire at once.
    vi.useFakeTimers()
    createNotifier({ duration: 10 ** 12 }).success('Sent')

    vi.advanceTimersByTime(10 ** 9)

    expect(toasts()).toHaveLength(1)
  })

  it('moves the focus to the next toast when a focused one closes', () => {
    const notifier = createNotifier()
    notifier.error('One')
    notifier.error('Two')
    const [first, second] = toasts()

    closeButton(first!).focus()
    closeButton(first!).click()

    expect(document.activeElement).toBe(closeButton(second!))
  })

  it('gives the focus back when the last focused toast closes', () => {
    document.body.innerHTML = '<input id="email">'
    const input = document.querySelector<HTMLInputElement>('#email')!
    createNotifier().error('Failed')
    const toast = toasts()[0]!

    input.focus()
    closeButton(toast).focus()
    closeButton(toast).click()

    expect(document.activeElement).toBe(input)
  })

  it('gives the focus back after the focus went from toast to toast', () => {
    document.body.innerHTML = '<input id="email">'
    const input = document.querySelector<HTMLInputElement>('#email')!
    const notifier = createNotifier()
    notifier.error('One')
    notifier.error('Two')
    const [first, second] = toasts()

    input.focus()
    closeButton(first!).focus()
    closeButton(first!).click()
    expect(document.activeElement).toBe(closeButton(second!))
    closeButton(second!).click()

    expect(document.activeElement).toBe(input)
  })

  it('keeps the three newest, but not away from the focus', () => {
    const notifier = createNotifier()
    for (const message of ['One', 'Two', 'Three']) {
      notifier.success(message)
    }
    closeButton(toasts()[0]!).focus()

    notifier.success('Four')

    expect(toasts().map(toast => toast.querySelector('.fetchit-toast__text')!.textContent)).toEqual(['One', 'Three', 'Four'])
    expect(document.querySelectorAll('.fetchit-toasts')).toHaveLength(1)
  })
})
