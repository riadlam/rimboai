import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

/**
 * Facebook's iOS feed browser can execute classic scripts while stalling both
 * native ESM and SystemJS. Keep this build to one eagerly-resolved IIFE.
 */
export default defineConfig({
    publicDir: false,
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    build: {
        outDir: 'public/build-meta',
        emptyOutDir: true,
        manifest: 'manifest.json',
        target: 'safari15',
        cssCodeSplit: false,
        rollupOptions: {
            input: 'resources/js/app-meta.tsx',
            output: {
                format: 'iife',
                name: 'RimboMetaApp',
                inlineDynamicImports: true,
                entryFileNames: 'assets/app-meta-[hash].js',
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
    },
});
