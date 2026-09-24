import { cpSync, mkdirSync } from 'node:fs'
import { defineConfig } from 'rolldown'

const assets = 'assets/components/fetchit'

// The public types ship next to the script, for sites written in TypeScript.
const copyTypes = {
  name: 'copy-types',
  buildEnd() {
    mkdirSync(`${assets}/js`, { recursive: true })
    cpSync('src/fetchit.d.ts', `${assets}/js/fetchit.d.ts`)
  },
}

export default defineConfig({
  input: 'src/index.ts',
  plugins: [copyTypes],
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
