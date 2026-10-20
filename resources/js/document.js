import Alpine from 'alpinejs';
import {prepareDisplayData} from './display.js';

window.canvasReady = false;
const errors = [];
const onError = event => errors.push(String(event.error?.message || event.message || event.reason || 'Canvas rendering failed.'));
window.addEventListener('error', onError);
window.addEventListener('unhandledrejection', onError);
const waitForImages = () => Promise.all([...document.images].map(image => image.complete ? Promise.resolve() : new Promise(resolve => {
    image.addEventListener('load', resolve, {once: true});
    image.addEventListener('error', resolve, {once: true});
})));
(async () => {
    const documentData = JSON.parse(document.querySelector('[data-canvas-document-data]')?.textContent || '{}');
    for (const node of document.querySelectorAll('[data-canvas-values]')) {
        Alpine.addScopeToNode(node, Alpine.reactive({data: prepareDisplayData({...documentData,...JSON.parse(node.dataset.canvasValues)})}));
    }
    Alpine.initTree(document.body);
    await Alpine.nextTick();
    await document.fonts.ready;
    await waitForImages();
    // Alpine reports expression errors asynchronously.
    await new Promise(resolve => setTimeout(resolve, 0));
    if (errors.length) throw new Error(errors.join('\n'));
    window.canvasReady = true;
})().catch(error => { console.error(error); window.canvasError = String(error); });
