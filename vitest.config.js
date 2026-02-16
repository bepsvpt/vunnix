import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
        globals: true,
        coverage: {
            provider: 'v8',
            reportsDirectory: 'coverage/js',
            include: ['resources/js/**/*.{js,vue}'],
            exclude: ['resources/js/**/*.test.js'],
        },
    },
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
});
