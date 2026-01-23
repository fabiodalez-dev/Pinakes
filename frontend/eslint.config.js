import js from '@eslint/js';
import globals from 'globals';

export default [
    js.configs.recommended,
    {
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.jquery,
                ...globals.commonjs,
                // Webpack/Node globals
                require: 'readonly',
                module: 'readonly',
                __dirname: 'readonly',
                // Project globals
                $: 'readonly',
                jQuery: 'readonly',
                DataTable: 'readonly',
                flatpickr: 'readonly',
                Swal: 'readonly',
                Chart: 'readonly',
                Sortable: 'readonly',
                bootstrap: 'readonly',
            }
        },
        rules: {
            // Errors that should block
            'no-undef': 'error',
            'no-unused-vars': ['error', {
                argsIgnorePattern: '^_',
                varsIgnorePattern: '^_',
                caughtErrors: 'none'
            }],
            'no-redeclare': 'error',
            'no-dupe-keys': 'error',
            'no-duplicate-case': 'error',
            'no-unreachable': 'error',
            'no-constant-condition': 'error',
            'no-empty': 'warn',
            'no-extra-semi': 'warn',
            'no-irregular-whitespace': 'warn',

            // Relaxed rules for existing codebase
            'no-prototype-builtins': 'off',
            'no-useless-escape': 'warn',
        }
    },
    {
        ignores: [
            'node_modules/**',
            'dist/**',
            'build/**',
            '*.min.js',
        ]
    }
];
