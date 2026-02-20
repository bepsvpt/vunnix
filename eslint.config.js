import antfu from '@antfu/eslint-config';

const FEATURE_ROOT_SEGMENT = '/resources/js/features/';
const LEGACY_STORE_IMPORTS = [
    '@/stores/conversations',
    '@/stores/conversations/*',
    '@/stores/admin',
    '@/stores/admin/*',
    '@/stores/dashboard',
    '@/stores/dashboard/*',
];

const featureBoundaryPlugin = {
    rules: {
        'no-cross-feature-imports': {
            meta: {
                type: 'problem',
                docs: {
                    description: 'Disallow direct cross-feature imports except via shared',
                },
                schema: [],
            },
            create(context) {
                const filename = (context.filename ?? context.getFilename()).replaceAll('\\', '/');
                const markerIndex = filename.lastIndexOf(FEATURE_ROOT_SEGMENT);
                if (markerIndex === -1) {
                    return {};
                }

                const featurePath = filename.slice(markerIndex + FEATURE_ROOT_SEGMENT.length);
                const sourceFeature = featurePath.split('/')[0];
                if (!sourceFeature) {
                    return {};
                }

                return {
                    ImportDeclaration(node) {
                        if (!node.source || typeof node.source.value !== 'string') {
                            return;
                        }

                        const match = node.source.value.match(/^@\/features\/([A-Za-z0-9_-]+)/);
                        if (!match) {
                            return;
                        }

                        const targetFeature = match[1];
                        if (
                            sourceFeature === targetFeature
                            || sourceFeature === 'shared'
                            || targetFeature === 'shared'
                        ) {
                            return;
                        }

                        context.report({
                            node: node.source,
                            message: `Cross-feature import disallowed: ${sourceFeature} -> ${targetFeature}. Use shared contracts/composables instead.`,
                        });
                    },
                };
            },
        },
    },
};

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
}, {
    plugins: {
        boundaries: featureBoundaryPlugin,
    },
    files: ['resources/js/features/**/*.{ts,js,vue}'],
    rules: {
        'boundaries/no-cross-feature-imports': 'error',
        'max-lines': ['error', {
            max: 1000,
            skipBlankLines: false,
            skipComments: false,
        }],
        'vue/max-lines-per-block': ['error', {
            style: 1000,
            template: 1000,
            script: 1000,
            skipBlankLines: false,
        }],
    },
}, {
    files: [
        'resources/js/components/**/*.{ts,js,vue}',
        'resources/js/pages/**/*.{ts,js,vue}',
        'resources/js/composables/**/*.{ts,js,vue}',
        'resources/js/router/**/*.{ts,js,vue}',
    ],
    ignores: ['**/*.test.*', '**/*.spec.*'],
    rules: {
        'no-restricted-imports': ['error', {
            patterns: [
                {
                    group: ['@/features/*/stores/*'],
                    message: 'Import from the feature index (e.g., @/features/chat) instead of deep store paths.',
                },
                {
                    group: LEGACY_STORE_IMPORTS,
                    message: 'UI layers must import stores from feature slices (e.g., @/features/chat), not legacy @/stores paths.',
                },
            ],
        }],
    },
});
