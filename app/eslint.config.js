import js from '@eslint/js';

export default [
    js.configs.recommended,
    {
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                window: 'readonly',
                document: 'readonly',
                history: 'readonly',
                navigator: 'readonly',
                console: 'readonly',
                fetch: 'readonly',
                Event: 'readonly',
                CustomEvent: 'readonly',
                localStorage: 'readonly',
                sessionStorage: 'readonly',
                btoa: 'readonly',
                atob: 'readonly',
                URL: 'readonly',
            },
        },
    },
    {
        files: ['webpack.config.js'],
        languageOptions: {
            globals: {
                process: 'readonly',
            },
        },
    },
];
