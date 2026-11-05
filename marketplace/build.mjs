// Build the description of the package page from the markdown of the card:
//
//   node marketplace/build.mjs            inline code as <kbd>
//   node marketplace/build.mjs --plain    inline code without tags
//
//   modstore.ru.md → modstore.ru.description.html
//   modstore.en.md → modstore.en.description.html
//
// modstore.pro takes HTML, not markdown, and its editor is the one of
// modx.pro, so the markup follows its rules:
//
//   * paragraphs are plain text, separated by a blank line: every newline
//     becomes a <br>, so <p> would only add two more;
//   * a list is one line, without newlines inside;
//   * code is <pre class="notranslate"> with no <code> inside: the editor
//     escapes a nested tag and shows it as text;
//   * inline code is <kbd>, the tag the editor itself puts around a <code>
//     in a line of text; a <code> of our own it turns into a <pre>, a block
//     across the whole width in the middle of a sentence.
//
// The notes of the card (the lines that start with ">") stay out of the
// description.

import { readFileSync, writeFileSync } from 'node:fs'

const dir = 'marketplace'
const cards = [
  { file: 'modstore.ru.md', out: 'modstore.ru.description.html', heading: '## Поле: Полное описание' },
  { file: 'modstore.en.md', out: 'modstore.en.description.html', heading: '## Field: Full description' },
]

// --plain leaves inline code without tags at all, in case the editor ever
// turns <kbd> into a block the way it does with <code>.
const plain = process.argv.includes('--plain')

const escape = text => text
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&#x27;')

// A URL goes into an attribute, and modstore decodes entities when it saves the
// description, so it must be a plain http(s), mailto, anchor or site-relative
// address with no quote or angle bracket in it.
function url (value) {
  const href = value.trim()
  if (!/^(https?:\/\/|mailto:|#|\/)/i.test(href) || /["'<>\s]/.test(href)) {
    throw new Error(`Bad URL in the markdown: ${value}`)
  }
  return escape(href)
}

// Inline markdown, on already escaped text: code first, so that the markup
// inside a code span is left alone.
function inline (text) {
  return escape(text)
    .replace(/`([^`]+)`/g, (all, code) => plain ? code : `<kbd>${code}</kbd>`)
    .replace(/\*\*([^*]+)\*\*/g, (all, bold) => `<strong>${bold}</strong>`)
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (all, label, href) => `<a href="${url(href.replace(/&amp;/g, '&'))}">${label}</a>`)
}

function section (markdown, heading) {
  const lines = markdown.split('\n')
  const start = lines.findIndex(line => line.trim() === heading)
  if (start === -1) {
    throw new Error(`No section "${heading}"`)
  }
  const rest = lines.slice(start + 1)
  const end = rest.findIndex(line => line.startsWith('## ') || line.trim() === '---')
  return rest.slice(0, end === -1 ? rest.length : end)
}

// A block and the gap after it: a blank line after a paragraph or a picture,
// a single newline after a heading, a list or a block of code, the way the
// pages of the catalogue are laid out.
function render (lines) {
  const blocks = []
  let list = null
  let code = null

  for (const line of lines) {
    if (code !== null) {
      if (line.trim() === '```') {
        blocks.push([`<pre class="notranslate">${escape(code.join('\n'))}</pre>`, '\n'])
        code = null
      } else {
        code.push(line)
      }
      continue
    }
    if (line.startsWith('```')) {
      code = []
      continue
    }
    if (line.startsWith('- ')) {
      list = list || []
      list.push(`<li>${inline(line.slice(2))}</li>`)
      continue
    }
    if (list && !line.trim()) {
      blocks.push([`<ul>${list.join('')}</ul>`, '\n'])
      list = null
      continue
    }
    if (!line.trim() || line.startsWith('>')) {
      continue
    }
    if (line.startsWith('### ')) {
      blocks.push([`<h3>${inline(line.slice(4))}</h3>`, '\n'])
      continue
    }
    const image = line.match(/^!\[([^\]]*)\]\(([^)]+)\)$/)
    if (image) {
      // modstore decodes the entities of the description when it saves it, so a
      // &quot; in alt text comes back as a raw quote and breaks the tag: curly
      // quotes go in instead, paired when they come in pairs, any left alone
      // becomes a closing one.
      const alt = escape(image[1].replace(/"([^"]*)"/g, '\u201c$1\u201d').replace(/"/g, '\u201d'))
      blocks.push([`<img src="${url(image[2])}" alt="${alt}">`, '\n\n'])
      continue
    }
    blocks.push([inline(line), '\n\n'])
  }
  if (list) {
    blocks.push([`<ul>${list.join('')}</ul>`, '\n'])
  }
  return blocks.map(([html, gap]) => html + gap).join('').trimEnd() + '\n'
}

for (const card of cards) {
  const markdown = readFileSync(`${dir}/${card.file}`, 'utf8')
  const html = render(section(markdown, card.heading))
  writeFileSync(`${dir}/${card.out}`, html)
  console.log(`${card.out}: ${html.length} characters`)
}
