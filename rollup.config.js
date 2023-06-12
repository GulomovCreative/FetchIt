import terser from '@rollup/plugin-terser'
import copy from 'rollup-plugin-copy'

export default {
  input: 'src/index.js',
  output: [
    {
      file: 'assets/components/fetchit/js/fetchit.js',
      format: 'iife',
    },
    {
      file: 'assets/components/fetchit/js/fetchit.min.js',
      format: 'iife',
      plugins: [
        terser(),
      ],
    },
  ],
  plugins: [
    copy({
      targets: [
        { src: 'node_modules/notyf/notyf.min.js', dest: 'assets/components/fetchit/lib/' },
        { src: 'node_modules/notyf/notyf.min.css', dest: 'assets/components/fetchit/lib/' },
      ],
    }),
  ],
};
