import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

const resolve = (path) => fileURLToPath(new URL(path, import.meta.url));

export default defineConfig(({ mode }) => {
    const document = mode === 'document';

    return {
        publicDir: false,
        build: {
            outDir: 'resources/dist',
            emptyOutDir: !document,
            target: 'es2022',
            minify: document,
            cssCodeSplit: !document,
            lib: {
                entry: resolve(document ? 'resources/js/document.js' : 'resources/js/canvas.js'),
                name: document ? 'CanvasDocument' : 'Canvas',
                formats: [document ? 'iife' : 'es'],
                fileName: () => document ? 'document.js' : 'canvas.js',
            },
            ...(!document && {
                rolldownOptions: {
                    input: {
                        canvas: resolve('resources/js/canvas.js'),
                        editor: resolve('resources/css/editor.css'),
                    },
                    output: {
                        assetFileNames: '[name].[ext]',
                    },
                },
            }),
        },
    };
});
