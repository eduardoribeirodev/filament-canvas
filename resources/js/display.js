import createCanvas from './canvas.js';

/** Apply native display values to frozen, server-authorized output. */
export function prepareDisplayData(values) {
    const state = createCanvas(), geometry = values.geometry || {};
    state.elementTypes[values.type] = {...state.elementTypes[values.type],kind:values.element_kind || 'element'};
    const block = {type: values.type, data: {
        properties: values, x_pt: geometry.x || 0, y_pt: geometry.y || 0,
        width_pt: geometry.width || 200, height_pt: geometry.height || 100,
        rotation_deg: geometry.rotation || 0,
    }};
    // file: URLs here were produced by the server's media preparation for Chromium.
    const safeImageUrl = state.safeImageUrl;
    state.safeImageUrl = value => /^file:\/\//i.test(String(value || '')) ? String(value) : safeImageUrl.call(state, value);
    state.mediaCollections = () => Object.entries(values.media || {}).map(([name, media]) => ({name, max_files: Array.isArray(media) ? Math.max(2, media.length) : 1}));
    state.mediaImages = (block, name) => {const media=values.media?.[name];return media ? (Array.isArray(media) ? media : [media]) : [];};
    state.image = () => values.media?.image?.image || values.image_url || '';
    return state.viewData(block);
}
