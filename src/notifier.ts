// The built-in notifier: the answers of the server and failed submissions
// as toasts in a corner of the page. Turned on with
// fetchit.frontend.default.notifier, or by hand with
// FetchIt.Message = FetchIt.createNotifier().
//
// The styles use single class selectors: they win over the site's rules for
// bare elements (button { ... }) and lose to any class rule of the site that
// comes later. Rules inside @layer (Tailwind 4) lose to them; the colours
// are CSS variables for that: --fetchit-toast-color, --fetchit-toast-success,
// --fetchit-toast-error.
//
// Screen readers hear a toast through two live regions that exist, empty,
// from the start, since a region added together with its text is often not
// announced: errors at once, successes when the reader is idle.

import { stripTags } from './text'

const STYLE_ID = 'fetchit-toasts-style'
// More than this and the oldest toast goes.
const MAX_TOASTS = 3
const DEFAULT_DURATION = 6000
// setTimeout() takes a 32-bit delay; a longer one fires at once.
const MAX_DELAY = 2 ** 31 - 1

// Read while the script runs: currentScript is null afterwards. With a
// Content-Security-Policy that allows the script by its nonce, the styles
// get the same nonce.
const nonce = (document.currentScript as HTMLScriptElement | null)?.nonce || undefined

const CSS = `
.fetchit-toasts {
  position: fixed; z-index: 2147483000; inset-block-end: 1rem; inset-inline-end: 1rem;
  display: flex; flex-direction: column; gap: .5rem;
  width: max-content; max-width: min(24rem, calc(100vw - 2rem)); pointer-events: none;
}
.fetchit-toast {
  display: flex; align-items: flex-start; gap: .75rem; margin: 0; padding: .75rem 1rem; border-radius: .5rem;
  color: var(--fetchit-toast-color, #fff); background: var(--fetchit-toast-success, #1b6e37);
  box-shadow: 0 .25rem 1rem rgb(0 0 0 / .2); line-height: 1.4; pointer-events: auto;
  animation: fetchit-toast-in .2s ease-out;
}
.fetchit-toast:where([data-type="error"]) { background: var(--fetchit-toast-error, #b3261e); }
.fetchit-toast__text { flex: 1; overflow-wrap: anywhere; }
.fetchit-toast__close {
  flex: none; margin: 0; padding: 0 .25rem; border: 0; border-radius: .25rem; background: none; box-shadow: none;
  color: inherit; font: inherit; font-size: 1.25rem; line-height: 1; cursor: pointer; opacity: .85;
}
.fetchit-toast__close:where(:hover, :focus-visible) { opacity: 1; outline: 2px solid currentColor; outline-offset: 2px; }
@keyframes fetchit-toast-in { from { opacity: 0; transform: translateY(.5rem); } }
@media (prefers-reduced-motion: reduce) { .fetchit-toast { animation: none; } }
@media (max-width: 30rem) { .fetchit-toasts { inset-inline: 1rem; width: auto; max-width: none; } }
`

let warned = false
// Where the focus came from into the toasts, to give it back when the last
// toast with the focus goes.
let origin: Element | null = null

function addStyles () {
  if (document.getElementById(STYLE_ID)) {
    return
  }
  const style = document.createElement('style')
  style.id = STYLE_ID
  if (nonce) {
    style.nonce = nonce
  }
  style.textContent = CSS
  // First in <head>: the styles of the site come later and win on a tie.
  ;(document.head ?? document.documentElement).prepend(style)
  if (!style.sheet && !warned) {
    warned = true
    console.warn('FetchIt: the styles of the notifier were blocked, probably by a Content-Security-Policy (style-src). Give the FetchIt script a nonce, or style .fetchit-toast yourself.')
  }
}

/**
 * An element the stylesheet cannot hide from sight but keeps for screen
 * readers; styled through the DOM, which a Content-Security-Policy allows.
 */
function visuallyHidden (element: HTMLElement) {
  Object.assign(element.style, {
    position: 'absolute', width: '1px', height: '1px', margin: '-1px', padding: '0',
    overflow: 'hidden', clip: 'rect(0 0 0 0)', whiteSpace: 'nowrap', border: '0',
  })
}

interface Regions {
  toasts: HTMLElement;
  polite: HTMLElement;
  assertive: HTMLElement;
}

function regions (): Regions {
  const parent = document.body ?? document.documentElement
  let toasts = document.querySelector<HTMLElement>('.fetchit-toasts')
  if (!toasts) {
    toasts = document.createElement('div')
    toasts.className = 'fetchit-toasts'
    parent.append(toasts)
  }
  const live = (role: 'status' | 'alert') => {
    let region = document.querySelector<HTMLElement>(`.fetchit-toasts-live[role="${role}"]`)
    if (!region) {
      region = document.createElement('div')
      region.className = 'fetchit-toasts-live'
      region.setAttribute('role', role)
      visuallyHidden(region)
      parent.append(region)
    }
    return region
  }
  return { toasts, polite: live('status'), assertive: live('alert') }
}

/**
 * Say a message in a live region. The region is emptied first and filled a
 * moment later, so a region added just now, or the same message twice, is
 * still announced.
 */
function announce (region: HTMLElement, message: string) {
  region.textContent = ''
  setTimeout(() => { region.textContent = message }, 100)
}

function duration (value: number | undefined): number {
  if (value === undefined) {
    return DEFAULT_DURATION
  }
  if (value === 0 || value === Infinity) {
    return 0
  }
  if (Number.isFinite(value) && value > 0) {
    return Math.min(value, MAX_DELAY)
  }
  console.warn(`FetchIt: createNotifier() got duration ${value}; using ${DEFAULT_DURATION}`)
  return DEFAULT_DURATION
}

function focusable (element: Element | null): element is HTMLElement {
  return element instanceof HTMLElement && element.isConnected && !element.hasAttribute('disabled')
}

/**
 * A toast that closes by itself after `delay` (0: never), or with its
 * button. The countdown stops while the pointer or the focus is on it and
 * starts over when both have left. When a toast with the focus goes, the
 * focus moves to the next toast, or back to where it came from.
 */
function show (type: 'success' | 'error', message: unknown, closeLabel: string, delay: number) {
  const content = stripTags(message == null ? '' : String(message)).trim()
  if (content === '') {
    return
  }
  addStyles()
  const { toasts, polite, assertive } = regions()

  const toast = document.createElement('div')
  toast.className = 'fetchit-toast'
  toast.dataset.type = type

  const text = document.createElement('div')
  text.className = 'fetchit-toast__text'
  text.textContent = content

  const close = document.createElement('button')
  close.type = 'button'
  close.className = 'fetchit-toast__close'
  close.setAttribute('aria-label', closeLabel)
  close.textContent = '×'

  let timer: ReturnType<typeof setTimeout> | undefined
  let hovered = false
  let focused = false

  const remove = () => {
    clearTimeout(timer)
    if (toast.contains(document.activeElement)) {
      const others = Array.from(toasts.querySelectorAll<HTMLButtonElement>('.fetchit-toast__close'))
        .filter(button => !toast.contains(button))
      const next = others.find(button => toast.compareDocumentPosition(button) & Node.DOCUMENT_POSITION_FOLLOWING) ?? others.at(-1)
      if (next) {
        next.focus()
      } else if (focusable(origin)) {
        origin.focus()
      } else {
        (document.activeElement as HTMLElement | null)?.blur()
      }
    }
    toast.remove()
  }
  const start = () => {
    clearTimeout(timer)
    if (delay > 0 && !hovered && !focused) {
      timer = setTimeout(remove, delay)
    }
  }
  const stop = () => clearTimeout(timer)

  close.addEventListener('click', remove)
  toast.addEventListener('mouseenter', () => { hovered = true; stop() })
  toast.addEventListener('mouseleave', () => { hovered = false; start() })
  toast.addEventListener('focusin', event => {
    const from = event.relatedTarget as Element | null
    if (!from || !toasts.contains(from)) {
      origin = from
    }
    focused = true
    stop()
  })
  toast.addEventListener('focusout', event => {
    if (!toast.contains(event.relatedTarget as Node | null)) {
      focused = false
      start()
    }
  })

  toast.append(text, close)
  toasts.append(toast)
  // Drop the oldest, but not one the visitor is on.
  for (const old of Array.from(toasts.children)) {
    if (toasts.children.length <= MAX_TOASTS) {
      break
    }
    if (!old.contains(document.activeElement)) {
      old.remove()
    }
  }
  announce(type === 'error' ? assertive : polite, content)
  start()
}

export function createNotifier (options: FetchItNotifierOptions = {}): FetchItNotifier {
  const closeLabel = options.closeLabel || 'Close'
  const delay = duration(options.duration)
  // The live regions exist before the first message, so it is announced.
  if (document.body) {
    regions()
  }

  return {
    success (message) {
      show('success', message, closeLabel, delay)
    },
    error (message) {
      show('error', message, closeLabel, delay)
    },
  }
}
