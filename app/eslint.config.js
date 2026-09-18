import js from '@eslint/js';
import tseslint from 'typescript-eslint';

export default [
    js.configs.recommended,
    {
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                window: 'readonly',
                document: 'readonly',
                console: 'readonly',
                fetch: 'readonly',
                Event: 'readonly',
                btoa: 'readonly',
                URL: 'readonly',
                EventSource: 'readonly',
            },
        },
    },
    ...tseslint.configs.recommended.map((config) => ({
        ...config,
        files: ['**/*.ts'],
    })),
    {
        files: ['webpack.config.js'],
        languageOptions: {
            globals: {
                process: 'readonly',
            },
        },
    },
];
