const defaultTheme = require('tailwindcss/defaultTheme');
const path = require('path');

module.exports = {
  content: [
    path.join(__dirname, '../app/Views/**/*.php'),  // Absolute path for correct resolution
    path.join(__dirname, '../storage/plugins/**/views/**/*.php'), // plugin UIs (settings + feature pages)
    path.join(__dirname, '../public/**/*.html')
  ],
  // Safelist classes that are dynamically used or defined in @layer utilities
  safelist: [
    'aspect-book',
    'aspect-video-custom',
    'aspect-square-custom',
    // Mobile-flat resets for settings pages. Plugin views (storage/plugins/**)
    // are NOT in `content` above, so the max-sm: classes they use would never be
    // generated — safelist guarantees they exist in the compiled CSS.
    'max-sm:!bg-transparent',
    'max-sm:!border-0',
    'max-sm:!rounded-none',
    'max-sm:!shadow-none',
    'max-sm:!p-0',
    'max-sm:!px-0',
    'max-sm:!py-3',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      screens: {
        ...defaultTheme.screens,
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
  ],
};
