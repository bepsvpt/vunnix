import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            output: {
                chunkFileNames: 'assets/[hash].js',
                entryFileNames: 'assets/[hash].js',
                assetFileNames: 'assets/[hash].[ext]',
                manualChunks(id) {
                    if (id.includes('@shikijs/langs/') || id.includes('shiki/dist/langs/')) {
                        return 'hl-l';
                    }
                    if (id.includes('@shikijs/') || id.includes('shiki/')) {
                        return 'hl-c';
                    }
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
