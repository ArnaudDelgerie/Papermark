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
        files: ['tests/js/**/*.ts'],
    })),
    // Typed rules on the shipped code only (QUA-09); tests stay untyped.
    // no-floating-promises and no-misused-promises come with recommendedTypeChecked.
    ...tseslint.configs.recommendedTypeChecked.map((config) => ({
        ...config,
        files: ['assets/**/*.ts'],
        languageOptions: {
            ...config.languageOptions,
            parserOptions: {
                ...config.languageOptions?.parserOptions,
                project: ['./tsconfig.json'],
                tsconfigRootDir: import.meta.dirname,
            },
        },
    })),
    {
        files: ['assets/**/*.ts'],
        rules: {
            // QUA-03, lot Front éditeur: the editor modules are still untyped
            // .js, so the unsafe-* family only fires across those boundaries
            // and on response.json(). Re-enable it when they are converted.
            '@typescript-eslint/no-unsafe-argument': 'off',
            '@typescript-eslint/no-unsafe-assignment': 'off',
            '@typescript-eslint/no-unsafe-call': 'off',
            '@typescript-eslint/no-unsafe-member-access': 'off',
            '@typescript-eslint/no-unsafe-return': 'off',
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
