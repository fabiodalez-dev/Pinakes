const defaultTheme = require('tailwindcss/defaultTheme');
const path = require('path');

module.exports = {
  content: [
    path.join(__dirname, '../app/Views/**/*.php'),  // Absolute path for correct resolution
    path.join(__dirname, '../public/**/*.html')
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

