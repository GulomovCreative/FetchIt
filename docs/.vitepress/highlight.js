import {
  BUNDLED_LANGUAGES
} from 'shiki'
import {
  addClass,
  createDiffProcessor,
  createFocusProcessor,
  createHighlightProcessor,
  createRangeProcessor,
  defineProcessor,
  getHighlighter,
} from 'shiki-processor'
import { createRequire } from 'module'
const require = createRequire(import.meta.url)
const modxGrammar = require('modx-tmlanguage/modx.tmLanguage.json')
import fenomGrammar from './syntaxes/fenom.tmLanguage.json'

const modx = {
  id: 'modx',
  scopeName: 'text.html.modx',
  grammar: modxGrammar,
  aliases: ['modx'],
}

const fenom = {
  id: 'fenom',
  scopeName: 'text.html.fenom',
  grammar: fenomGrammar,
  aliases: ['fenom'],
}

const attrsToLines = (attrs) => {
  attrs = attrs.replace(/^(?:\[.*?\])?.*?([\d,-]+).*/, '$1').trim()
  const result = []
  if (!attrs) {
    return []
  }
  attrs
    .split(',')
    .map((v) => v.split('-').map((v) => parseInt(v, 10)))
    .forEach(([start, end]) => {
      if (start && end) {
        result.push(
          ...Array.from({ length: end - start + 1 }, (_, i) => start + i)
        )
      } else {
        result.push(start)
      }
    })
  return result.map((v) => ({
    line: v,
    classes: ['highlighted']
  }))
}

const errorLevelProcessor = defineProcessor({
  name: 'error-level',
  handler: createRangeProcessor({
    error: ['highlighted', 'error'],
    warning: ['highlighted', 'warning']
  })
})

export async function highlight(
  theme = 'material-theme-palenight',
  defaultLang = '',
  logger = console
) {
  const hasSingleTheme = typeof theme === 'string' || 'name' in theme
  const getThemeName = (themeValue) =>
    typeof themeValue === 'string' ? themeValue : themeValue.name
  const processors = [
    createFocusProcessor(),
    createHighlightProcessor({ hasHighlightClass: 'highlighted' }),
    createDiffProcessor(),
    errorLevelProcessor
  ]

  const highlighter = await getHighlighter({
    themes: hasSingleTheme ? [theme] : [theme.dark, theme.light],
    langs: [...BUNDLED_LANGUAGES, modx, fenom],
    processors
  })

  const styleRE = /<pre[^>]*(style=".*?")/
  const preRE = /^<pre(.*?)>/
  const vueRE = /-vue$/
  const lineNoRE = /:(no-)?line-numbers$/
  const mustacheRE = /\{\{.*?\}\}/g

  return (str, lang, attrs) => {
    const vPre = vueRE.test(lang) ? '' : 'v-pre'
    lang =
      lang.replace(lineNoRE, '').replace(vueRE, '').toLowerCase() || defaultLang

    if (lang) {
      const langLoaded = highlighter.getLoadedLanguages().includes(lang)
      if (!langLoaded && lang !== 'ansi') {
        logger.warn(
          c.yellow(
            `\nThe language '${lang}' is not loaded, falling back to '${
              defaultLang || 'txt'
            }' for syntax highlighting.`
          )
        )
        lang = defaultLang
      }
    }

    const lineOptions = attrsToLines(attrs)
    const cleanup = (str) =>
      str
        .replace(preRE, (_, attributes) => `<pre ${vPre}${attributes}>`)
        .replace(styleRE, (_, style) => _.replace(style, ''))

    const mustaches = new Map()

    const removeMustache = (s) => {
      if (vPre) return s
      return s.replace(mustacheRE, (match) => {
        let marker = mustaches.get(match)
        if (!marker) {
          marker = nanoid()
          mustaches.set(match, marker)
        }
        return marker
      })
    }

    const restoreMustache = (s) => {
      mustaches.forEach((marker, match) => {
        s = s.replaceAll(marker, match)
      })
      return s
    }

    str = removeMustache(str)

    const codeToHtml = (theme) => {
      return cleanup(
        restoreMustache(
          lang === 'ansi'
            ? highlighter.ansiToHtml(str, {
                lineOptions,
                theme: getThemeName(theme)
              })
            : highlighter.codeToHtml(str, {
                lang,
                lineOptions,
                theme: getThemeName(theme)
              })
        )
      )
    }

    if (hasSingleTheme) return codeToHtml(theme)
    const dark = addClass(codeToHtml(theme.dark), 'vp-code-dark', 'pre')
    const light = addClass(codeToHtml(theme.light), 'vp-code-light', 'pre')
    return dark + light
  }
}
