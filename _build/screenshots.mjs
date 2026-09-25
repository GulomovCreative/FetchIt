// Screenshots of the built-in notifier for the README, the documentation and
// articles, in docs/images/:
//
//   npm run screenshots
//
// A demo feedback form with the built script (the npm script builds it
// first), so the pictures follow any change of the styles. Chromium from
// Playwright; @2x for sharp images on retina screens.
//
//   notifier-{desktop,mobile,dark}-{ru,en}.png  a page with the form after
//                                               an error and a success
//   notifier-readme-{ru,en}.png                 the same in a narrower frame,
//                                               where the toasts read well at
//                                               the width of the README
//   toast-{success,error}-{ru,en}.png           one toast, cropped

import { mkdirSync, readFileSync } from 'node:fs'
import { chromium, devices } from '@playwright/test'

const out = 'docs/images'
const script = readFileSync('assets/components/fetchit/js/fetchit.min.js', 'utf8')

const texts = {
  ru: {
    lang: 'ru',
    title: 'Обратная связь',
    lead: 'Ответим в течение рабочего дня.',
    name: 'Имя',
    email: 'Email',
    message: 'Сообщение',
    send: 'Отправить',
    error: 'Проверьте поля формы',
    success: 'Спасибо! Сообщение отправлено.',
    close: 'Закрыть',
  },
  en: {
    lang: 'en',
    title: 'Contact us',
    lead: 'We reply within one working day.',
    name: 'Name',
    email: 'Email',
    message: 'Message',
    send: 'Send',
    error: 'Please check the form',
    success: 'Thank you! Your message has been sent.',
    close: 'Close',
  },
}

function html (t, dark, left) {
  const colors = dark
    ? { page: '#111827', card: '#1f2937', text: '#f3f4f6', muted: '#9ca3af', field: '#111827', border: '#374151', accent: '#60a5fa' }
    : { page: '#f3f4f6', card: '#ffffff', text: '#111827', muted: '#6b7280', field: '#ffffff', border: '#d1d5db', accent: '#2563eb' }

  return `<!doctype html>
<html lang="${t.lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FetchIt</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: ${left ? 'center start' : 'center'}; padding: 2rem ${left ? '4rem' : '1rem'};
    background: ${colors.page}; color: ${colors.text}; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  form { width: min(28rem, 100%); padding: 2rem; border-radius: 1rem; background: ${colors.card};
    box-shadow: 0 1px 3px rgb(0 0 0 / .08), 0 8px 24px rgb(0 0 0 / .06); }
  h1 { margin: 0 0 .25rem; font-size: 1.5rem; }
  p { margin: 0 0 1.5rem; color: ${colors.muted}; }
  label { display: block; margin-bottom: 1rem; font-weight: 500; }
  input, textarea { display: block; width: 100%; margin-top: .375rem; padding: .625rem .75rem; border: 1px solid ${colors.border};
    border-radius: .5rem; background: ${colors.field}; color: inherit; font: inherit; }
  textarea { min-height: 6rem; resize: vertical; }
  button[type="submit"] { width: 100%; padding: .75rem; border: 0; border-radius: .5rem; background: ${colors.accent};
    color: #fff; font: inherit; font-weight: 600; }
</style>
</head>
<body>
<form>
  <h1>${t.title}</h1>
  <p>${t.lead}</p>
  <label>${t.name}<input name="name"></label>
  <label>${t.email}<input name="email" type="email"></label>
  <label>${t.message}<textarea name="message"></textarea></label>
  <button type="submit">${t.send}</button>
</form>
</body>
</html>`
}

async function open (browser, t, { dark = false, left = false, device = { viewport: { width: 1280, height: 800 } } } = {}) {
  const context = await browser.newContext({ ...device, deviceScaleFactor: 2, reducedMotion: 'reduce' })
  const page = await context.newPage()
  await page.setContent(html(t, dark, left))
  await page.addScriptTag({ content: script })
  // Long enough to take the picture; the first message was an error, the second a success.
  await page.evaluate(({ close }) => {
    window.FetchIt.Message = window.FetchIt.createNotifier({ closeLabel: close, duration: 600_000 })
  }, t)
  return { context, page }
}

async function toast (page, t, type) {
  await page.evaluate(({ hook, message }) => window.FetchIt.Message[hook](message), { hook: type, message: t[type] })
  return page.locator(`.fetchit-toast[data-type="${type}"]`).last()
}

// The toast with some of the page around it, so its shadow stays in.
async function crop (page, element, path) {
  const box = await element.boundingBox()
  const pad = 16
  await page.screenshot({ path, clip: { x: box.x - pad, y: box.y - pad, width: box.width + pad * 2, height: box.height + pad * 2 } })
}

mkdirSync(out, { recursive: true })
const browser = await chromium.launch()

for (const t of Object.values(texts)) {
  for (const [name, options] of [
    ['desktop', {}],
    // The form on the left, the toasts beside it.
    ['readme', { left: true, device: { viewport: { width: 1000, height: 620 } } }],
    ['mobile', { device: devices['iPhone 13'] }],
    ['dark', { dark: true }],
  ]) {
    const { context, page } = await open(browser, t, options)
    await toast(page, t, 'error')
    await toast(page, t, 'success')
    await page.screenshot({ path: `${out}/notifier-${name}-${t.lang}.png` })
    await context.close()
  }

  for (const type of ['success', 'error']) {
    const { context, page } = await open(browser, t)
    await crop(page, await toast(page, t, type), `${out}/toast-${type}-${t.lang}.png`)
    await context.close()
  }
}

await browser.close()
console.log(`Screenshots are in ${out}/`)
