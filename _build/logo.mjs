// Render the logo from .github/logo/logo.html:
//
//   npm run logo
//
//   logo.png, logo@2x.png  320x240, for the README
//   social.png             1280x640, the preview of links to the repository
//                          (upload it in Settings > General > Social preview)
//
// The fonts come from Google Fonts, so it needs the network.

import { pathToFileURL } from 'node:url'
import { chromium } from '@playwright/test'

const dir = '.github/logo'
const url = pathToFileURL(`${dir}/logo.html`).href
const browser = await chromium.launch()

async function render (scale, path, transform) {
  const page = await browser.newPage({ viewport: { width: 320, height: 240 }, deviceScaleFactor: scale })
  await page.goto(url)
  await page.waitForSelector('body[data-ready]')
  if (transform) {
    await page.evaluate(transform)
  }
  await page.screenshot({ path })
  await page.close()
}

await render(1, `${dir}/logo.png`)
await render(2, `${dir}/logo@2x.png`)

// The same logo, centred on a 1280x640 card: GitHub's size for the preview.
const page = await browser.newPage({ viewport: { width: 1280, height: 640 } })
await page.goto(url)
await page.waitForSelector('body[data-ready]')
// Outfit has no Cyrillic: the line under the logo is set in Onest.
await page.addStyleTag({ url: 'https://fonts.googleapis.com/css2?family=Onest:wght@400&display=block' })
await page.evaluate(async () => {
  document.documentElement.style.cssText = 'width: 1280px; height: 640px'
  document.body.style.cssText = 'width: 1280px; height: 640px; display: grid; place-items: center'
  const logo = document.querySelector('.logo')
  logo.style.cssText = 'height: auto; padding: 0; transform: scale(2.6)'
  const line = document.createElement('div')
  line.textContent = 'Формы MODX без перезагрузки страницы, с защитой от спама'
  line.style.cssText = "position: absolute; bottom: 120px; width: 100%; text-align: center; font: 400 30px Onest, sans-serif; color: #555"
  document.body.append(line)
  await document.fonts.load('400 30px Onest', line.textContent)
})
await page.screenshot({ path: `${dir}/social.png` })
await browser.close()
console.log(`The logo is in ${dir}/`)
