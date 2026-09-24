import { afterEach, describe, expect, it, vi } from 'vitest'
import { createNotifier } from '../../src/notifier'

function toasts() {
  return Array.from(document.querySelectorAll<HTMLElement>('.fetchit-toast'))
}

afterEach(() => {
  vi.useRealTimers()
  document.body.innerHTML = ''
  document.getElementById('fetchit-toasts-style')?.remove()
})

describe('the built-in notifier', () => {
  it('shows a success and an error with their roles', () => {
    const notifier = createNotifier()

    notifier.success('Sent')
    notifier.error('Check the form')

    const [success, error] = toasts()
    expect(success!.dataset.type).toBe('success')
    expect(success!.getAttribute('role')).toBe('status')
    expect(success!.querySelector('.fetchit-toast__text')!.textContent).toBe('Sent')
    expect(error!.dataset.type).toBe('error')
    expect(error!.getAttribute('role')).toBe('alert')
  })

  it('shows the message as text', () => {
    createNotifier().success('<img src=x onerror=alert(1)>Thanks, <b>Ann</b>')

    const text = toasts()[0]!.querySelector('.fetchit-toast__text')!
    expect(text.innerHTML).toBe('Thanks, Ann')
  })

  it('shows nothing for an empty message', () => {
    createNotifier().error(' <br> ')

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
    expect(styles[0]!.textContent).toContain(':where(.fetchit-toast)')
  })

  it('closes with its button, which has a label', () => {
    createNotifier({ closeLabel: 'Закрыть' }).success('Sent')
    const close = toasts()[0]!.querySelector<HTMLButtonElement>('.fetchit-toast__close')!

    expect(close.type).toBe('button')
    expect(close.getAttribute('aria-label')).toBe('Закрыть')
    close.click()

    expect(toasts()).toHaveLength(0)
  })

  it('closes by itself, but not while it is read', () => {
    vi.useFakeTimers()
    const notifier = createNotifier({ duration: 1000 })

    notifier.success('Sent')
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

  it('pauses while the focus is on it too', () => {
    vi.useFakeTimers()
    createNotifier({ duration: 1000 }).error('Failed')
    const toast = toasts()[0]!

    toast.querySelector('button')!.dispatchEvent(new FocusEvent('focusin', { bubbles: true }))
    vi.advanceTimersByTime(5000)

    expect(toasts()).toHaveLength(1)
  })

  it('keeps the three newest', () => {
    const notifier = createNotifier()

    for (const message of ['One', 'Two', 'Three', 'Four']) {
      notifier.success(message)
    }

    expect(toasts().map(toast => toast.textContent?.replace('×', ''))).toEqual(['Two', 'Three', 'Four'])
    expect(document.querySelectorAll('.fetchit-toasts')).toHaveLength(1)
  })
})
