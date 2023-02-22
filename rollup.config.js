import terser from '@rollup/plugin-terser'

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
};
