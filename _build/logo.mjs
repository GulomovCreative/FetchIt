// Render the logo from .github/logo/logo.html:
//
//   npm run logo
//
//   logo.png, logo@2x.png            260x100, transparent, for light pages
//   logo-dark.png, logo-dark@2x.png  the same for dark pages (GitHub's dark theme)
//   social-en.png, social-ru.png     1280x640, the preview of links to the
//                                    repository, with a line in English or
//                                    Russian (upload one in Settings >
//                                    General > Social preview)
//   icon-<size>.png                  the mark alone (icon.svg), square and
//                                    transparent: 64, 128, 256, 512, 1024
//   modstore.png, modstore@2x.png    320x240 and 640x480 on white, the size
//                                    modstore.pro takes for the logo of a
//                                    package
//   icon-extras.png                  512x512 on white, the mark at 72% of the
//                                    side: the icon of a package on
//                                    extras.modx.com
//
// The fonts come from Google Fonts. Without them the script stops before it
// writes anything, rather than leaving logos in fallback fonts.

import { readFileSync, writeFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'
import { chromium } from '@playwright/test'

const dir = '.github/logo'
const url = pathToFileURL(`${dir}/logo.html`).href
const icon = pathToFileURL(`${dir}/icon.svg`).href
const browser = await chromium.launch()

// document.fonts.ready resolves even when the fonts failed to load.
async function requireFonts (page, families) {
  const missing = await page.evaluate(names => names.filter(name =>
    ![...document.fonts].some(face => face.family.replace(/"/g, '') === name && face.status === 'loaded')), families)
  if (missing.length) {
    throw new Error(`The fonts did not load (${missing.join(', ')}); the logo needs the network for Google Fonts`)
  }
}

async function logo (scale, query = '') {
  const page = await browser.newPage({ viewport: { width: 260, height: 100 }, deviceScaleFactor: scale })
  await page.goto(url + query)
  await page.waitForSelector('body[data-ready]')
  await requireFonts(page, ['Outfit', 'JetBrains Mono'])
  const png = await page.screenshot({ omitBackground: true })
  await page.close()
  return png
}

// The mark alone: the 76px SVG scaled to the size, sharp at any of them.
async function iconPng (size) {
  const page = await browser.newPage({ viewport: { width: 76, height: 76 }, deviceScaleFactor: size / 76 })
  await page.goto(icon)
  const png = await page.screenshot({ omitBackground: true })
  await page.close()
  return png
}

// The mark on white, 368px of a 512px square: the icon of a package on
// extras.modx.com. The site shows the icon on a coloured box, so the square is
// opaque, and the mark takes 72% of it like the icons of the other packages of
// the author, so the list of their extras looks alike.
async function extrasIcon () {
  const page = await browser.newPage({ viewport: { width: 512, height: 512 } })
  const svg = readFileSync(`${dir}/icon.svg`, 'utf8').replace(/width="76" height="76"/, 'width="368" height="368"')
  await page.setContent(`<body style="margin: 0; width: 512px; height: 512px; display: grid; place-items: center; background: #fff">${svg}</body>`)
  const png = await page.screenshot()
  await page.close()
  return png
}

// The logo on white, centred on a 320x240 card: the logo of a package on
// modstore.pro. The mark and the wordmark keep the sizes of the other packages
// of the author, so the tiles of the catalogue look alike.
async function modstore (scale) {
  const page = await browser.newPage({ viewport: { width: 320, height: 240 }, deviceScaleFactor: scale })
  await page.goto(url)
  await page.waitForSelector('body[data-ready]')
  await page.evaluate(() => {
    document.documentElement.style.cssText = 'width: 320px; height: 240px; background: #fff'
    document.body.style.cssText = 'margin: 0; width: 320px; height: 240px; background: #fff; overflow: hidden'
    document.querySelector('.logo').style.height = '240px'
  })
  await requireFonts(page, ['Outfit', 'JetBrains Mono'])
  const png = await page.screenshot()
  await page.close()
  return png
}

// The logo on white, centred on a 1280x640 card: GitHub's size for the preview.
async function social (text) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 640 } })
  await page.goto(url)
  await page.waitForSelector('body[data-ready]')
  // Outfit has no Cyrillic: the line under the logo is set in Onest.
  await page.addStyleTag({ url: 'https://fonts.googleapis.com/css2?family=Onest:wght@400&display=block' })
  await page.evaluate(async caption => {
    document.documentElement.style.cssText = 'width: 1280px; height: 640px'
    document.body.style.cssText = 'width: 1280px; height: 640px; display: grid; place-items: center; background: #fff'
    const mark = document.querySelector('.logo')
    mark.style.cssText = 'height: auto; padding: 0; transform: scale(2.6)'
    const line = document.createElement('div')
    line.textContent = caption
    line.style.cssText = 'position: absolute; bottom: 120px; width: 100%; text-align: center; font: 400 30px Onest, sans-serif; color: #555'
    document.body.append(line)
    await document.fonts.load('400 30px Onest', line.textContent)
  }, text)
  await requireFonts(page, ['Outfit', 'JetBrains Mono', 'Onest'])
  const png = await page.screenshot()
  await page.close()
  return png
}

try {
  // Every picture first, then the files: a failure leaves the old ones.
  const files = {
    'logo.png': await logo(1),
    'logo@2x.png': await logo(2),
    'logo-dark.png': await logo(1, '?dark'),
    'logo-dark@2x.png': await logo(2, '?dark'),
    'modstore.png': await modstore(1),
    'modstore@2x.png': await modstore(2),
    'icon-extras.png': await extrasIcon(),
    'social-en.png': await social('MODX forms without a page reload, protected from spam'),
    'social-ru.png': await social('Формы MODX без перезагрузки страницы, с защитой от спама'),
    ...Object.fromEntries(await Promise.all([64, 128, 256, 512, 1024].map(async size => [`icon-${size}.png`, await iconPng(size)]))),
  }
  for (const [name, png] of Object.entries(files)) {
    writeFileSync(`${dir}/${name}`, png)
  }
  console.log(`The logo is in ${dir}/`)
} finally {
  await browser.close()
}
