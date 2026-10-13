import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';

await build({
    absWorkingDir: fileURLToPath(new URL('.', import.meta.url)),
    entryPoints: ['resources/js/canvas.js', 'resources/css/editor.css'],
    outdir: 'resources/dist',
    entryNames: '[name]',
    bundle: true,
    format: 'esm',
    target: 'es2022',
    legalComments: 'eof',
});
