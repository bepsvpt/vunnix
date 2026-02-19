import antfu from '@antfu/eslint-config';

export default antfu({
    vue: true,
    typescript: true,

    stylistic: {
        indent: 4,
        semi: true,
        quotes: 'single',
    },

    rules: {
        'style/brace-style': ['error', '1tbs'],
        'no-alert': 'off',
        'ts/no-explicit-any': 'error',
        'no-restricted-imports': ['error', {
            patterns: [{
                group: ['@/features/*/stores/*'],
                message: 'Import from the feature index (e.g., @/features/chat) instead of deep store paths.',
            }],
        }],
    },
});
