import { cpSync, mkdirSync, rmSync } from 'node:fs'
import { defineConfig } from 'rolldown'

const assets = 'assets/components/fetchit'

// The public types ship next to the script, for sites written in TypeScript.
// lib/ held Notyf before FetchIt 4; a build left from then would put it in
// the package.
const copyTypes = {
  name: 'copy-types',
  buildEnd() {
    mkdirSync(`${assets}/js`, { recursive: true })
    cpSync('src/fetchit.d.ts', `${assets}/js/fetchit.d.ts`)
    rmSync(`${assets}/lib`, { recursive: true, force: true })
  },
}

export default defineConfig({
  input: 'src/index.ts',
  // The browsers of browserslist in package.json include Chromium 78 (UC
  // Browser, Opera Mobile), which has no ?., ?? or ??=: lower the syntax to
  // ES2019, which every browser there runs.
  transform: { target: 'es2019' },
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
