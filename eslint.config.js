import antfu from '@antfu/eslint-config';

export default antfu({
    vue: true,
    typescript: false,

    stylistic: {
        indent: 4,
        semi: true,
        quotes: 'single',
    },

    rules: {
        'style/brace-style': ['error', '1tbs'],
        'no-alert': 'off',
    },
});
