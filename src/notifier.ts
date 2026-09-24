// The built-in notifier: the answers of the server as toasts in a corner of
// the page. Turned on with fetchit.frontend.default.notifier, or by hand with
// FetchIt.Message = FetchIt.createNotifier().
//
// The styles use :where(), so any rule of the site wins over them, and CSS
// variables for the colours: --fetchit-toast-color, --fetchit-toast-success,
// --fetchit-toast-error.

import { stripTags } from './text'

const STYLE_ID = 'fetchit-toasts-style'
// More than this and the oldest toast goes.
const MAX_TOASTS = 3

const CSS = `
:where(.fetchit-toasts) {
  position: fixed; z-index: 2147483000; inset-block-end: 1rem; inset-inline-end: 1rem;
  display: flex; flex-direction: column; gap: .5rem;
  width: max-content; max-width: min(24rem, calc(100vw - 2rem)); pointer-events: none;
}
:where(.fetchit-toast) {
  display: flex; align-items: flex-start; gap: .75rem; padding: .75rem 1rem; border-radius: .5rem;
  color: var(--fetchit-toast-color, #fff); background: var(--fetchit-toast-success, #1b6e37);
  box-shadow: 0 .25rem 1rem rgb(0 0 0 / .2); line-height: 1.4; pointer-events: auto;
  animation: fetchit-toast-in .2s ease-out;
}
:where(.fetchit-toast[data-type="error"]) { background: var(--fetchit-toast-error, #b3261e); }
:where(.fetchit-toast__text) { flex: 1; overflow-wrap: anywhere; }
:where(.fetchit-toast__close) {
  flex: none; padding: 0 .25rem; border: 0; border-radius: .25rem; background: none;
  color: inherit; font: inherit; font-size: 1.25rem; line-height: 1; cursor: pointer; opacity: .85;
}
:where(.fetchit-toast__close:hover, .fetchit-toast__close:focus-visible) { opacity: 1; outline: 2px solid currentColor; outline-offset: 2px; }
@keyframes fetchit-toast-in { from { opacity: 0; transform: translateY(.5rem); } }
@media (prefers-reduced-motion: reduce) { :where(.fetchit-toast) { animation: none; } }
@media (max-width: 30rem) { :where(.fetchit-toasts) { inset-inline: 1rem; width: auto; max-width: none; } }
`

function addStyles () {
  if (document.getElementById(STYLE_ID)) {
    return
  }
  const style = document.createElement('style')
  style.id = STYLE_ID
  style.textContent = CSS
  // First in <head>: the styles of the site come later and win on a tie too.
  document.head.prepend(style)
}

function region (): HTMLElement {
  let element = document.querySelector<HTMLElement>('.fetchit-toasts')
  if (!element) {
    element = document.createElement('div')
    element.className = 'fetchit-toasts'
    document.body.append(element)
  }
  return element
}

/**
 * A toast that closes by itself after `duration`, or with its button. The
 * countdown pauses while the pointer or the focus is on it, so a long
 * message can be read.
 */
function show (type: 'success' | 'error', message: string, closeLabel: string, duration: number) {
  const content = stripTags(String(message ?? '')).trim()
  if (content === '') {
    return
  }
  addStyles()

  const toast = document.createElement('div')
  toast.className = 'fetchit-toast'
  toast.dataset.type = type
  // An error is announced at once, a success when the reader is idle.
  toast.setAttribute('role', type === 'error' ? 'alert' : 'status')

  const text = document.createElement('div')
  text.className = 'fetchit-toast__text'
  text.textContent = content

  const close = document.createElement('button')
  close.type = 'button'
  close.className = 'fetchit-toast__close'
  close.setAttribute('aria-label', closeLabel)
  close.textContent = '×'

  let timer: ReturnType<typeof setTimeout> | undefined
  const remove = () => {
    clearTimeout(timer)
    toast.remove()
  }
  const start = () => {
    clearTimeout(timer)
    timer = setTimeout(remove, duration)
  }
  const pause = () => clearTimeout(timer)
  close.addEventListener('click', remove)
  toast.addEventListener('mouseenter', pause)
  toast.addEventListener('mouseleave', start)
  toast.addEventListener('focusin', pause)
  toast.addEventListener('focusout', start)

  toast.append(text, close)
  const toasts = region()
  toasts.append(toast)
  while (toasts.children.length > MAX_TOASTS) {
    toasts.firstElementChild?.remove()
  }
  start()
}

export function createNotifier (options: FetchItNotifierOptions = {}): Required<Pick<FetchItMessage, 'success' | 'error'>> {
  const closeLabel = options.closeLabel || 'Close'
  const duration = options.duration ?? 6000

  return {
    success (message) {
      show('success', message, closeLabel, duration)
    },
    error (message) {
      show('error', message, closeLabel, duration)
    },
  }
}
