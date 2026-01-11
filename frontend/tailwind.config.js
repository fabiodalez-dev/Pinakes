const defaultTheme = require('tailwindcss/defaultTheme');
const path = require('path');

module.exports = {
  content: [
    path.join(__dirname, '../app/Views/**/*.php'),  // Absolute path for correct resolution
    path.join(__dirname, '../public/**/*.html')
  ],
  // Safelist classes that are dynamically used or defined in @layer utilities
  safelist: [
    'aspect-book',
    'aspect-video-custom',
    'aspect-square-custom',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      screens: {
        ...defaultTheme.screens,
      },
    },
  },
  plugins: [require('@tailwindcss/forms'), require('autoprefixer')],
};
