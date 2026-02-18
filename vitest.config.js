import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.ts'],
        globals: true,
        coverage: {
            provider: 'v8',
            reportsDirectory: 'coverage/js',
            include: ['resources/js/**/*.{ts,vue}'],
            exclude: [
                'resources/js/**/*.test.ts',
                'resources/js/env.d.ts',
                'resources/js/types/**',
                'resources/js/app.ts',
                'resources/js/bootstrap.ts',
            ],
        },
    },
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
});
