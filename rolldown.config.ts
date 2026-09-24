import { cpSync, mkdirSync } from 'node:fs'
import { defineConfig } from 'rolldown'

const assets = 'assets/components/fetchit'

// Notyf is not bundled: the plugin links it separately when
// fetchit.frontend.default.notifier is on.
const copyNotyf = {
  name: 'copy-notyf',
  buildEnd() {
    mkdirSync(`${assets}/lib`, { recursive: true })
    for (const file of ['notyf.min.js', 'notyf.min.css']) {
      cpSync(`node_modules/notyf/${file}`, `${assets}/lib/${file}`)
    }
  },
}

export default defineConfig({
  input: 'src/index.ts',
  plugins: [copyNotyf],
  output: [
    {
      file: `${assets}/js/fetchit.js`,
      format: 'iife',
    },
    {
      file: `${assets}/js/fetchit.min.js`,
      format: 'iife',
      minify: true,
    },
  ],
})
