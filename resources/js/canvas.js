import {drawingTools} from './drawing.js';
import {alignMove, alignResize, elementBounds} from './snapping.js';

const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
const richColor = value => {
    const color = String(value ?? '');
    if (/^#(?:[a-f\d]{3}|[a-f\d]{6}|[a-f\d]{8})$/i.test(color)) return color;
    const rgb = color.match(/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i);
    return rgb && rgb.slice(1).every(channel => Number(channel) <= 255) ? '#' + rgb.slice(1).map(channel => Number(channel).toString(16).padStart(2, '0')).join('') : '';
};
const richLink = value => /^(?:https?:|mailto:|tel:|#)/i.test(String(value ?? '').trim()) ? String(value).trim() : '';
const cssRichColor = value => richColor(value) || (/^oklch\(\s*[\d.]+%?\s+[\d.]+\s+[\d.]+(?:\s*\/\s*[\d.]+%?)?\s*\)$/i.test(String(value ?? '')) ? String(value).trim() : '');

/** Render the same small Tiptap vocabulary accepted by the PHP RichText normalizer. */
export function renderRichDocument(document, textColors = {}) {
    let visited = 0;
    const render = (node, depth = 0) => {
        if (!node || typeof node !== 'object' || depth > 20 || ++visited > 2000) return '';
        if (node.type === 'text') {
            let html = escapeHtml(String(node.text ?? '').slice(0, 20000));
            for (const mark of Array.isArray(node.marks) ? node.marks : []) {
                const tag = {bold: 'strong', italic: 'em', underline: 'u', strike: 's', code: 'code', subscript: 'sub', superscript: 'sup'}[mark.type];
                if (tag) html = `<${tag}>${html}</${tag}>`;
                if (mark.type === 'link') { const href = richLink(mark.attrs?.href); if (href) html = `<a href="${escapeHtml(href)}" rel="noopener noreferrer">${html}</a>`; }
                if (['textColor', 'textStyle'].includes(mark.type)) { const name=mark.attrs?.['data-color'] ?? mark.attrs?.color;const color=cssRichColor(textColors[name]?.color ?? name);if(color)html=`<span style="color:${color}" data-color="${escapeHtml(name)}">${html}</span>`; }
                if (mark.type === 'highlight') { const color = richColor(mark.attrs?.color) || '#ffff00'; html = `<mark style="background-color:${color}" data-color="${color}">${html}</mark>`; }
            }
            return html;
        }
        if (node.type === 'hardBreak') return '<br>';
        if (node.type === 'horizontalRule') return '<hr>';
        const children = (Array.isArray(node.content) ? node.content : []).map(child => render(child, depth + 1)).join('');
        if (node.type === 'doc') return children;
        const tag = {paragraph: 'p', heading: `h${Math.max(1, Math.min(6, Number(node.attrs?.level) || 2))}`, blockquote: 'blockquote', bulletList: 'ul', orderedList: 'ol', listItem: 'li', codeBlock: 'pre'}[node.type];
        if (!tag) return '';
        const align = {start: 'left', end: 'right', left: 'left', right: 'right', center: 'center', justify: 'justify'}[node.attrs?.textAlign];
        const attributes = (['paragraph', 'heading'].includes(node.type) && align ? ` style="text-align:${align}"` : '') + (node.type === 'orderedList' ? ` start="${Math.max(1, Math.trunc(Number(node.attrs?.start) || 1))}"` : '');
        return `<${tag}${attributes}>${node.type === 'codeBlock' ? `<code>${children}</code>` : children || (node.type === 'paragraph' ? '<br>' : '')}</${tag}>`;
    };
    return render(document);
}

export function plainRichDocument(document) {
    let visited = 0;
    const render = (node, depth = 0) => {
        if (!node || depth > 20 || ++visited > 2000) return '';
        if (node.type === 'text') return String(node.text ?? '');
        if (node.type === 'hardBreak') return '\n';
        const children = (node.content || []).map(child => render(child, depth + 1)).join('');
        return children + (['paragraph', 'heading', 'listItem', 'blockquote', 'codeBlock'].includes(node.type) ? '\n' : '');
    };
    return render(document).replace(/\n+$/, '');
}

/** Sanitize before inserting cached HTML or clipboard content in either canvas document. */
export function sanitizeRichHtml(html, ownerDocument = globalThis.document) {
    if (!ownerDocument?.createElement) return escapeHtml(String(html ?? ''));
    const template = ownerDocument.createElement('template');
    template.innerHTML = String(html ?? '').slice(0, 200000);
    let visited = 0;
    const clean = (node, depth = 0) => {
        if (depth > 20 || ++visited > 2000) return '';
        if (node.nodeType === 3) return escapeHtml(node.textContent);
        if (node.nodeType !== 1) return '';
        const tag = node.tagName.toLowerCase();
        if (['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'img', 'video', 'audio', 'input', 'button', 'textarea', 'select'].includes(tag)) return '';
        const children = [...node.childNodes].map(child => clean(child, depth + 1)).join('');
        const normalizedTag = {b: 'strong', i: 'em', del: 's', strike: 's', div: 'p'}[tag] || tag;
        if (!['p', 'br', 'strong', 'em', 'u', 's', 'code', 'pre', 'sub', 'sup', 'a', 'span', 'mark', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'hr'].includes(normalizedTag)) return children;
        if (['br', 'hr'].includes(normalizedTag)) return `<${normalizedTag}>`;
        let attributes = '';
        if (normalizedTag === 'a') { const href = richLink(node.getAttribute('href')); if (!href) return children; attributes = ` href="${escapeHtml(href)}" rel="noopener noreferrer"`; }
        const color = cssRichColor(node.style?.color || node.style?.getPropertyValue('--color') || node.dataset?.color);
        const background = richColor(node.style?.backgroundColor || node.dataset?.color);
        const align = {left: 'left', center: 'center', right: 'right', justify: 'justify', start: 'left', end: 'right'}[node.style?.textAlign];
        const styles = [];
        if (normalizedTag === 'span' && color) styles.push(`color:${color}`);
        if (normalizedTag === 'mark') styles.push(`background-color:${background || '#ffff00'}`);
        if ((normalizedTag === 'p' || /^h[1-6]$/.test(normalizedTag)) && align) styles.push(`text-align:${align}`);
        if (styles.length) attributes += ` style="${styles.join(';')}"`;
        if(normalizedTag === 'span' && /^[a-z\-]+$|^#[a-f\d]{3,8}$/i.test(node.dataset?.color || ''))attributes+=` data-color="${escapeHtml(node.dataset.color)}"`;
        if (normalizedTag === 'ol') attributes += ` start="${Math.max(1, Math.trunc(Number(node.getAttribute('start')) || 1))}"`;
        return `<${normalizedTag}${attributes}>${children}</${normalizedTag}>`;
    };
    return [...template.content.childNodes].map(node => clean(node)).join('');
}

function richDocumentSignature(document) {
    return JSON.stringify(document, (key, value) => {
        if(value===null || typeof value!=='object' || Array.isArray(value))return value;
        const entries=Object.entries(value).filter(([property, item])=>key!=='attrs' || (item!==null && !(property==='textAlign' && ['start','left'].includes(item))));
        if(key==='attrs' && entries.length===0)return undefined;
        return Object.fromEntries(entries.sort(([left],[right])=>left.localeCompare(right)));
    });
}

function plainDocument(text) {
    return {type: 'doc', content: String(text ?? '').split('\n').map(line => ({type: 'paragraph', content: line ? [{type: 'text', text: line}] : []}))};
}

/**
 * Canvas geometry uses pt in the document and one CSS px per pt in the editor.
 * PreviewRenderer supplies a .page and .block[data-editor-index] nodes.
 */
export default function createCanvas(initial = [], assets = [], pageWidth = 595.28, pageHeight = 841.89, previewHtml = '', options = {}) {
    const minZoom = Number(options.minZoom ?? .25), maxZoom = Number(options.maxZoom ?? 3);
    if (!Number.isFinite(minZoom) || !Number.isFinite(maxZoom) || minZoom <= 0 || maxZoom <= minZoom) {
        throw new RangeError('Canvas zoom limits must be positive numbers with maxZoom greater than minZoom.');
    }
    const state = {
        ...drawingTools,
        blocks: JSON.parse(JSON.stringify(initial)), documentData: options.data || {}, assets, pageWidth: Number(pageWidth), pageHeight: Number(pageHeight), previewHtml, selected: null,
        cropIndex: null, panel: 'properties', minZoom, maxZoom, zoom: Math.max(minZoom,Math.min(maxZoom,1)), grid: true, snapPixels: 5, alignmentSnap: true, snapGuides: [], viewport: {x:32,y:32,width:0,height:0}, editorFitZoom: null, editorViewportSize: null, disposeEditorViewport: null, disposeZoomSlider: null, dirty: false, saving: false, imageUploading: false, error: '', undoStack: [], redoStack: [], gesture: null,
        messages: {
            drawing_min_points:'Use at least two points, or three for a closed drawing.',
            load_properties_error: 'Unable to load element properties.',
            validation_error: 'Check property values and validation messages.',
            upload_error: 'Unable to upload media. Check its format, size and validation messages.',
            save_error: 'Unable to save. Check validation messages or reload if another window changed the page.',
            create_error: 'Unable to create the element.',
            missing_template: 'Missing view template for :type.',
            ...(options.messages || {}),
        },
        labels: {title: 'Title', text: 'Text', asset: 'Image', image: 'Image', photo: 'Photo', drawing: 'Drawing', line: 'Line', rectangle:'Rectangle', ellipse:'Ellipse', triangle:'Triangle', arrow:'Arrow', table: 'Table', 'data-table': 'Table', ...(options.labels || {})},
        elementTypes: {title:{kind:'text'},text:{kind:'text'},asset:{kind:'image',media_collections:{image:{reference_field:'image_id',max_files:1}}},image:{kind:'image',media_collections:{image:{reference_field:'image_id',max_files:1}}},photo:{kind:'image',media_collections:{image:{reference_field:'image_id',max_files:1}}},drawing:{kind:'drawing'},line:{kind:'line'},rectangle:{kind:'shape'},ellipse:{kind:'shape'},triangle:{kind:'shape'},arrow:{kind:'shape'},'data-table':{kind:'table'},table:{kind:'table'},...(options.elementTypes || {})}, templates: options.templates || {},
        previewReady: false, previewLoaded: false, previewNodes: new Map(), previewSignatures: new Map(), previewFrame: null, nextKey: 1, nativePinch: null, destroyed: false, disposeWatch: null, disposeSelectionWatch: null, textEditing: null, inlineMountTimer: null, inlineFocusTimer: null, richEditorSignature: null,richTextColors:options.richTextColors || {},previewFontStyle:null, inspectorGeneration:0, inspectorSelection:null, inspectorSignature:null, inspectorValueSignature:null, inspectorRequestRevision:0, inspectorHydrating:false, inspectorInvalid:false, inspectorApplying:false, inspectorPending:null, inspectorPromise:null, inspectorFields:[], inspectorEditRevisions:new Map(), disposeInspectorWatch:null, adding:false,
        init() {
        this.prepare();
        this.$nextTick(() => { if(this.destroyed)return; if(options.fitViewport)this.observeEditorViewport();else this.fit(); this.$refs.preview.srcdoc = this.previewHtml; });
        this.disposeWatch = this.$watch('blocks', () => { this.schedulePreview(); this.syncInspector(); });
        this.disposeSelectionWatch = this.$watch('selected', () => { if(this.textEditing && this.current?._key !== this.textEditing.key)this.commitTextEditing();this.syncInspector(); });
        if(this.$wire && this.$watch)this.disposeInspectorWatch=this.$watch('$wire.inspectorData', values=>this.queueInspectorChanges(values));
        this.syncInspector();
    },
        schedulePreview() {
        if (this.destroyed || this.previewFrame !== null) return;
        if(typeof requestAnimationFrame!=='function'){this.syncPreview();return;}
        this.previewFrame = requestAnimationFrame(() => { this.previewFrame = null; if(!this.destroyed)this.syncPreview(); });
    },
        fit() {
            const stage=this.$refs.stage;
            if(!stage)return;
            const zoom=Math.max(this.minZoom,Math.min(this.maxZoom,1,(stage.clientWidth-64)/this.pageWidth,(stage.clientHeight-64)/this.pageHeight));
            this.placeViewport(zoom,Math.max(32,(stage.clientWidth-this.pageWidth*zoom)/2),32);
            this.editorFitZoom=this.zoom;
        },
        observeEditorViewport() {
            if(!this.$el || typeof window==='undefined')return;
            this.disposeEditorViewport?.();
            let frame=null;
            const resize=()=>{
                if(frame!==null)return;
                frame=requestAnimationFrame(()=>{frame=null;if(!this.destroyed)this.resizeEditorViewport();});
            };
            const observer=typeof ResizeObserver==='function'?new ResizeObserver(resize):null;
            observer?.observe(this.$el);
            if(this.$refs.stage)observer?.observe(this.$refs.stage);
            window.addEventListener('resize',resize);
            window.visualViewport?.addEventListener('resize',resize);
            this.disposeEditorViewport=()=>{
                observer?.disconnect();
                window.removeEventListener('resize',resize);
                window.visualViewport?.removeEventListener('resize',resize);
                if(frame!==null)cancelAnimationFrame(frame);
            };
            this.resizeEditorViewport();
        },
        resizeEditorViewport() {
            const workspace=this.$refs.workspace,stage=this.$refs.stage;
            if(!workspace || !stage || this.destroyed || typeof window==='undefined')return;
            // A Resource Page fills the available window; an embedded Schema keeps its own height.
            if(this.$el.closest('.canvas-editor-page') && window.matchMedia('(min-width:1024px)').matches){
                const top=workspace.getBoundingClientRect().top+window.scrollY;
                const height=Math.max(280,Math.floor((window.visualViewport?.height || window.innerHeight)-top-(this.$refs.help?.offsetHeight || 0)-24));
                workspace.style.setProperty('--fc-workspace-height',height+'px');
            }else workspace.style.removeProperty('--fc-workspace-height');
            const size=stage.clientWidth+':'+stage.clientHeight;
            if(size===this.editorViewportSize)return;
            this.editorViewportSize=size;
            if(this.editorFitZoom===null || Math.abs(this.zoom-this.editorFitZoom)<.0001)this.fit();
        },
        mountZoomSlider(element) {
            const component=globalThis.Alpine?.$data?.(element),slider=component?.slider;
            if(!slider || this.destroyed)return;
            this.disposeZoomSlider?.();
            // The Filament Alpine component owns the slider and its local state.
            slider.updateOptions({animate:false},false);
            let dragging=false;
            const sync=()=>{
                if(dragging || !element.isConnected || this.destroyed)return;
                const percentage=Math.round(this.zoom*100);
                // Never feed a user slide back into the field's state watcher.
                if(Math.round(Number(slider.get(true)))===percentage)return;
                if(Number(component.state)!==percentage)component.state=percentage;
                else slider.set(percentage);
            };
            const syncDisabled=()=>{if(this.saving)slider.disable();else slider.enable();};
            slider.on('start.canvasZoom',()=>{dragging=true;});
            slider.on('slide.canvasZoom',(values,handle,unencoded,isTap)=>{
                // Remove the tap transition's temporary input lock for this zoom control.
                if(isTap)element.classList.remove('noUi-state-tap');
                if(!this.saving)this.setZoom(Number(values[0])/100);
            });
            slider.on('end.canvasZoom',()=>{dragging=false;sync();});
            const unwatchZoom=this.$watch('zoom',sync),unwatchSaving=this.$watch('saving',syncDisabled);
            this.disposeZoomSlider=()=>{
                slider.off('.canvasZoom');unwatchZoom?.();unwatchSaving?.();
            };
            sync();syncDisabled();
        },
        viewportStyle() {
            return {position:'relative',overflow:'clip',width:Math.max(this.viewport.width,this.viewport.x+this.pageWidth*this.zoom+32)+'px',height:Math.max(this.viewport.height,this.viewport.y+this.pageHeight*this.zoom+32)+'px'};
        },
        paperStyle() {
            return {position:'absolute',left:this.viewport.x+'px',top:this.viewport.y+'px',width:this.pageWidth+'px',height:this.pageHeight+'px',transform:`scale(${this.zoom})`,cursor:this.drawingSession?.creating?'crosshair':'default'};
        },
        placeViewport(zoom,left,top) {
            const stage=this.$refs.stage;
            if(!stage)return;
            const scrollLeft=Math.max(0,32-left),scrollTop=Math.max(0,32-top);
            this.zoom=zoom;
            this.viewport={
                x:left+scrollLeft,y:top+scrollTop,
                width:Math.max(stage.clientWidth+scrollLeft,left+scrollLeft+this.pageWidth*zoom+32),
                height:Math.max(stage.clientHeight+scrollTop,top+scrollTop+this.pageHeight*zoom+32),
            };
            // Apply scale, position and scroll together before paint. Alpine binds the same styles.
            // Explicit space also keeps the anchor fixed when a smaller paper cannot scroll back.
            const space=this.$refs.viewport,paper=this.$refs.paper;
            if(space?.style)Object.assign(space.style,this.viewportStyle());
            if(paper?.style)Object.assign(paper.style,this.paperStyle());
            stage.scrollLeft=scrollLeft;stage.scrollTop=scrollTop;this.positionInlineEditor();
            // Browsers round scroll offsets; absorb that rounding in the paper's subpixel position.
            this.viewport.x=left+stage.scrollLeft;this.viewport.y=top+stage.scrollTop;
            if(paper?.style)Object.assign(paper.style,this.paperStyle());
        },
        snap(value) {
            const step=Number(this.snapPixels);
            return this.grid && Number.isFinite(step) && step>0 ? Math.round(value/step)*step : value;
        },
        endGesture() { this.gesture=null;this.snapGuides=[]; },
        selectBlock(index) { if(this.drawingSession && !this.finishDrawing())this.cancelDrawing();if(this.textEditing)this.commitTextEditing();this.selected=index;this.syncInspector(); },
        get imageTypes() { return Object.keys(this.elementTypes).filter(type=>this.elementTypes[type].kind==='image'); },
        isText(block) { return this.elementTypes[block?.type]?.kind==='text'; },
        syncInspector() {
            if(this.drawingSession?.creating)return;
            if(this.destroyed)return;
            this.syncRichEditor();
            const reference=this.$el?.querySelector?.('[data-canvas-reference-select]');
            if(reference)reference.dataset.canvasElementKey=this.current?String(this.current._key):'';
            const imageFields=this.$el?.querySelectorAll?.('[data-canvas-image-select]') || [this.$el?.querySelector?.('[data-canvas-image-select]')].filter(Boolean);
            for(const image of imageFields){
                const component=globalThis.Alpine?.$data?.(image),policy=this.mediaCollection(this.current,image.dataset.canvasMediaCollection);
                const imageBlock=policy?this.current:null;
                if(component){
                    image.dataset.canvasElementKey=imageBlock?String(imageBlock._key):'';
                    const id=imageBlock?.data[policy.reference_field] ?? (policy?.max_files>1?[]:null);
                    if(JSON.stringify(component.state??null)!==JSON.stringify(id??null)){
                        image.dataset.canvasHydrating='true';component.state=id;
                        this.$nextTick?.(()=>delete image.dataset.canvasHydrating);
                    }
                }
            }
            const key=this.current?String(this.current._key):null;
            if(this.$wire?.selectInspector && key!==this.inspectorSelection)this.hydrateInspector();
            options.inspectorSync?.call(this);
        },
        async hydrateInspector() {
            const block=this.current, key=block?String(block._key):null;
            const generation=++this.inspectorGeneration;
            this.inspectorSelection=key;this.inspectorRequestRevision=0;
            this.inspectorSignature=block?JSON.stringify(block.data.properties):null;
            this.inspectorPending=null;this.inspectorValueSignature=null;
            this.inspectorHydrating=true;this.schedulePreview();
            try {
                // Filament's native color watcher settles before its schema is removed.
                const colorFields=this.$el?.querySelectorAll?.('[data-canvas-color-picker]') || [];
                const settleUntil=Math.max(0,...[...colorFields].map(field=>Number(field.dataset.canvasColorUpdatedAt || 0)+250));
                const settleFor=settleUntil-Date.now();
                if(settleFor>0)await new Promise(resolve=>setTimeout(resolve,settleFor));
                if(this.destroyed || generation!==this.inspectorGeneration)return;
                const result=await this.$wire.selectInspector(block?.type ?? null,key,block?this.serializeBlock(block):{},generation);
                if(this.destroyed || generation!==this.inspectorGeneration || key!==(this.current?String(this.current._key):null))return;
                if(result?.stale)return;
                if(block && result?.normalized){block.data.properties=result.normalized;this.inspectorSignature=JSON.stringify(block.data.properties);}
                this.inspectorFields=result?.fields || [];
                this.inspectorValueSignature=this.inspectorStateSignature(result?.state ?? this.$wire.inspectorData ?? {});
                this.inspectorInvalid=false;
                if(this.error===this.messages.load_properties_error)this.error='';
                this.syncRichEditor();
            } catch {if(generation===this.inspectorGeneration)this.error=this.messages.load_properties_error;}
            finally {if(generation===this.inspectorGeneration){this.inspectorHydrating=false;this.schedulePreview();this.$nextTick?.(()=>{this.syncRichEditor();if(this.textEditing)this.mountTextEditor();});}}
        },
        inspectorStateSignature(values) {
            const comparison=JSON.parse(JSON.stringify(values ?? {}));
            for(const field of this.inspectorFields){
                if(!field.rich && field.path!=='rich_text')continue;
                const parts=field.path.split('.');let target=comparison;
                for(const part of parts.slice(0,-1)){target=target?.[part];if(!target)break;}
                const property=parts.at(-1);
                if(target && Object.hasOwn(target,property))target[property]=richDocumentSignature(target[property]);
            }
            return JSON.stringify(comparison);
        },
        queueInspectorChanges(values) {
            if(this.destroyed || this.saving || this.inspectorHydrating || this.inspectorApplying || !this.current || !this.$wire?.updateInspector)return;
            const key=String(this.current._key),form=this.$el?.querySelector?.('[data-canvas-properties]');
            if(!form || form.dataset.canvasInspectorKey!==key || Number(form.dataset.canvasInspectorGeneration)!==this.inspectorGeneration)return;
            const signature=this.inspectorStateSignature(values);
            if(signature===this.inspectorValueSignature)return;
            this.inspectorValueSignature=signature;
            this.inspectorPending={key,generation:this.inspectorGeneration,revision:++this.inspectorRequestRevision,values:JSON.parse(JSON.stringify(values ?? {}))};
            this.inspectorEditRevisions.set(key,{generation:this.inspectorGeneration,revision:this.inspectorRequestRevision});
            this.projectInspectorValues(this.inspectorPending.values);
        },
        projectInspectorValues(values) {
            const block=this.current;if(!block)return;
            const properties=JSON.parse(JSON.stringify(block.data.properties));
            for(const field of this.inspectorFields){
                const parts=field.path.split('.');let source=values;
                for(const part of parts){if(source===null || source===undefined || !Object.hasOwn(source,part)){source=undefined;break;}source=source[part];}
                if(source===undefined)continue;
                if(field.numeric && (source===null || source==='' || Number.isFinite(Number(source))))source=(source===null || source==='')?null:(field.integer?Math.trunc(Number(source)):Number(source));
                if(field.list && source && !Array.isArray(source))source=Object.values(source);
                if(field.list && field.simple && Array.isArray(source))source=source.map(row=>row && typeof row==='object' && Object.hasOwn(row,field.simple)?row[field.simple]:row);
                let target=properties;
                for(const part of parts.slice(0,-1)){if(!target[part] || typeof target[part]!=='object')target[part]={};target=target[part];}
                if(source===null && !Object.hasOwn(target,parts.at(-1)))continue;
                target[parts.at(-1)]=JSON.parse(JSON.stringify(source));
            }
            if(properties.rich_text?.type==='doc'){
                properties.html=renderRichDocument(properties.rich_text,this.richTextColors);
                properties.text=plainRichDocument(properties.rich_text);
            }
            if(JSON.stringify(properties)!==JSON.stringify(block.data.properties)){this.checkpoint();block.data.properties=properties;}
            this.inspectorSignature=JSON.stringify(block.data.properties);this.schedulePreview();
        },
        async flushInspector() {
            const pending=this.inspectorPending;
            if(!pending){if(this.inspectorPromise)await this.inspectorPromise;return;}
            this.inspectorPending=null;
            const block=this.blocks.find(item=>String(item._key)===pending.key);
            if(!block || String(this.current?._key)!==pending.key || pending.generation!==this.inspectorGeneration)return;
            const payload=this.serializeBlock(block);
            const promise=(async()=>{
                try {
                    const result=await this.$wire.updateInspector(pending.values,pending.key,pending.generation,pending.revision,payload);
                    if(this.destroyed || result?.stale)return;
                    this.inspectorInvalid=false;this.error='';this.applyInspectorPatch(result,payload);
                } catch {if(pending.generation===this.inspectorGeneration){this.inspectorInvalid=true;this.error=this.messages.validation_error;}}
            })();
            this.inspectorPromise=promise;
            await promise;
            if(this.inspectorPromise===promise)this.inspectorPromise=null;
        },
        applyInspectorPatch(result, payload) {
            const block=this.blocks.find(item=>String(item._key)===String(result?.key));
            const token=this.inspectorEditRevisions.get(String(result?.key));
            if(!block || !token || result.generation!==token.generation || result.revision!==token.revision || JSON.stringify(block.data.properties)!==JSON.stringify(payload.data.properties))return;
            const properties={...block.data.properties,...result.patch};
            for(const property of result.removed || [])delete properties[property];
            if(JSON.stringify(properties)!==JSON.stringify(block.data.properties))block.data.properties=properties;
            this.inspectorApplying=true;if(String(this.current?._key)===String(result.key))this.inspectorSignature=JSON.stringify(block.data.properties);
            this.schedulePreview();
            const release=()=>{this.inspectorApplying=false;};
            if(this.$nextTick)this.$nextTick(release);else release();
        },
        serializeBlock(block) { const payload=JSON.parse(JSON.stringify(block));delete payload._key;return payload; },
        mediaCollections(block) {
            return Object.entries(this.elementTypes[block?.type]?.media_collections || {}).map(([name,policy])=>({name,reference_field:'image_id',max_files:1,...policy}));
        },
        mediaCollection(block,name=null) { const policies=this.mediaCollections(block);return name?policies.find(policy=>policy.name===name):policies[0]; },
        selectAsset(id,key=this.current?._key,collection=null) {
            if(this.saving || String(this.current?._key)!==String(key))return;
            const policy=this.mediaCollection(this.current,collection);if(!policy)return;
            const ids=Array.isArray(id)?id:(id===null || id===''?[]:[id]);
            if(ids.length>policy.max_files || new Set(ids.map(String)).size!==ids.length)return;
            const assets=ids.map(value=>this.assets.find(asset=>String(asset.id)===String(value)));
            if(assets.some(asset=>!asset))return;
            const block=this.current,value=policy.max_files>1?assets.map(asset=>asset.id):assets[0]?.id ?? null;
            if(JSON.stringify(block.data[policy.reference_field]??null)===JSON.stringify(value))return;
            this.checkpoint();block.data[policy.reference_field]=value;
            if(policy.reference_field==='image_id')block.data.image_url=assets[0]?this.safeImageUrl(assets[0].image):'';
            if(Object.hasOwn(block.data.properties,'image_transform'))block.data.properties.image_transform=null;
            this.inspectorSignature=JSON.stringify(block.data.properties);
            this.cropIndex=null;this.syncInspector();this.syncPreview();
        },
        mediaImages(block,collection) {
            const policy=this.mediaCollection(block,collection);if(!policy)return [];
            const refs=block.data[policy.reference_field],ids=Array.isArray(refs)?refs:(refs===null || refs===undefined?[]:[refs]);
            return ids.map(id=>this.assets.find(asset=>String(asset.id)===String(id))).filter(Boolean);
        },
        async uploadImage(wire,collection=null) {
            if(this.imageUploading || this.saving)return;
            const key=this.current?._key,policy=this.mediaCollection(this.current,collection);
            if(!policy)return;
            this.imageUploading=true;this.error='';
            try {
                const result=await wire.uploadAsset(policy.name);
                if(this.destroyed)return;
                if(!result?.asset)throw new Error('Media upload did not return an asset.');
                if(Array.isArray(result?.assets))this.assets=result.assets;
                else if(result?.asset){this.assets=this.assets.filter(asset=>String(asset.id)!==String(result.asset.id));this.assets.push(result.asset);}
                if(String(this.current?._key)===String(key)){
                    const ids=(result.inserted || [result.asset]).map(asset=>asset.id);
                    this.selectAsset(policy.max_files>1?ids:ids[0],key,policy.name);
                }
            } catch {this.error=this.messages.upload_error;}
            finally {this.imageUploading=false;}
        },
        syncRichEditor() {
            const element = this.$el?.querySelector?.('[data-canvas-rich-editor]');
            const component = element && globalThis.Alpine?.$data?.(element);
            const editor = component?.getEditor?.();
            if(!element || !editor || editor.isDestroyed || this.textEditing)return;
            this.richTextColors=editor.extensionManager?.extensions?.find(extension=>extension.name==='textColor')?.options?.textColors || this.richTextColors;
            const block=this.current, isText=this.isText(block);
            element.dataset.canvasElementKey=isText?String(block._key):'';
            if(!isText){this.richEditorSignature=null;return;}
            const contents=block.data.properties || {};
            const value=contents.rich_text || plainDocument(this.text(block));
            const signature=JSON.stringify([block._key,value]);
            if(signature===this.richEditorSignature && element.dataset.canvasSyncedKey===String(block._key))return;
            const previousKey=element.dataset.canvasSyncedKey;
            const editorContent=typeof value==='string'?editor.getHTML?.():editor.getJSON?.();
            element.dataset.canvasHydrating='true';
            if(previousKey!==String(block._key)||JSON.stringify(editorContent)!==JSON.stringify(value)){
                editor.commands.setContent(value,{emitUpdate:false});
                // Replacing the document must not carry another element's native undo history.
                const state=editor.state;
                if(state?.constructor?.create && editor.view?.updateState){
                    editor.view.updateState(state.constructor.create({doc:state.doc,plugins:state.plugins}));
                }
            }
            component.shouldUpdateState=false;
            component.state=editor.getJSON?.() || value;
            element.dataset.canvasSyncedKey=String(block._key);
            this.richEditorSignature=signature;
            this.$nextTick?.(()=>delete element.dataset.canvasHydrating);
        },
        applyRichText(detail, key=this.current?._key) {
            if(this.saving || this.textEditing || key===null || key===undefined)return;
            if(this.$el?.querySelector?.('[data-canvas-rich-editor]')?.dataset?.canvasHydrating)return;
            const block=this.blocks.find(block=>String(block._key)===String(key));
            if(!block || !this.isText(block) || String(this.current?._key)!==String(key))return;
            const document=detail?.document || detail;
            if(document?.type!=='doc')return;
            const richText=JSON.parse(JSON.stringify(document));
            const html=renderRichDocument(richText,this.richTextColors),text=plainRichDocument(richText);
            const contents=block.data.properties || {};
            if(richDocumentSignature(contents.rich_text)===richDocumentSignature(richText))return;
            this.checkpoint();
            block.data.properties={...contents,rich_text:richText,html,text};
            this.richEditorSignature=JSON.stringify([block._key,richText]);
            this.inspectorSignature=JSON.stringify(block.data.properties);
            this.$wire?.$set?.('inspectorData.rich_text',richText,false);
            this.schedulePreview();
        },
        textHtml(block) {
            if(this.isTextEditing(block) && this.textEditing.document)return this.textEditing.html;
            const contents=block.data.properties || {};
            if(contents.rich_text?.type==='doc')return renderRichDocument(contents.rich_text,this.richTextColors);
            return escapeHtml(this.text(block) ?? '').replace(/\n/g,'<br>');
        },
        isTextEditing(block) { return Boolean(block && this.textEditing && String(block._key)===String(this.textEditing.key)); },
        isTextEditorReady(block) { return this.isTextEditing(block) && Boolean(this.textEditing.element?.isConnected) && !this.inspectorHydrating; },
        startTextEditing(event,index) {
            const block=this.blocks[index];
            if(this.saving || !this.isText(block))return;
            event?.preventDefault?.();event?.stopPropagation?.();
            if(this.isTextEditing(block))return;
            if(this.textEditing)this.commitTextEditing();
            this.selected=index;this.cropIndex=null;this.endGesture();
            this.textEditing={key:block._key,html:this.textHtml(block),document:null,original:JSON.stringify(block.data.properties || {}),element:null};
            this.syncPreview();this.$nextTick?.(()=>this.mountTextEditor());
        },
        nativeInlineEditor() {
            const owner=this.$el?.closest?.('.fc-editor,.canvas-editor') || this.$el;
            const node=owner?.querySelector?.('[data-canvas-inline-rich-editor]');
            const component=node && globalThis.Alpine?.$data?.(node);
            return {node,component,editor:component?.getEditor?.()};
        },
        inlineEditorStyle() {
            const block=this.blocks.find(item=>String(item._key)===String(this.textEditing?.key));
            const paper=this.$refs?.paper;
            if(!block || !paper)return {};
            const rect=paper.getBoundingClientRect(),d=block.data,c=d.properties || {};
            return {position:'fixed',left:(rect.left+Number(d.x_pt)*this.zoom)+'px',top:(rect.top+Number(d.y_pt)*this.zoom)+'px',width:Number(d.width_pt)*this.zoom+'px',height:Number(d.height_pt)*this.zoom+'px',fontSize:Number(c.font_size ?? (block.type==='title'?24:12))*this.zoom+'px',color:richColor(c.color)||'#111111',visibility:this.isTextEditorReady(block)?'visible':'hidden',zIndex:'40'};
        },
        textEditorHtml(block) { return this.isTextEditing(block)?this.textEditing.html:this.textHtml(block); },
        textEditorStyle(block) {
            const c=block.data.properties || {},previewNode=this.previewNodes.get(block._key);
            const fontFamily=previewNode?.ownerDocument?.defaultView?.getComputedStyle?.(previewNode)?.fontFamily || 'Arial,sans-serif';
            return {fontSize:this.inlineEditorStyle().fontSize,color:this.inlineEditorStyle().color,minHeight:Number(block.data.height_pt)*this.zoom+'px',fontWeight:'400',fontStyle:'normal',fontFamily,lineHeight:String(Math.max(.5,Math.min(10,Number(c.leading??1.25)))),textAlign:'left',columnCount:Math.max(1,Math.min(12,Math.trunc(Number(c.columns??1)))),backgroundColor:previewNode?.querySelector('.canvas-rich-text')?'transparent':richColor(c.background_color)||'transparent',padding:Math.max(0,Math.min(100,Number(c.padding)||0))*this.zoom+'px',borderRadius:Math.max(0,Math.min(100,Number(c.border_radius)||0))*this.zoom+'px',whiteSpace:'pre-wrap',transform:`rotate(${-Number(block.data.rotation_deg || 0)}deg)`,transformOrigin:`${Number(block.data.width_pt)*this.zoom/2}px ${Number(block.data.height_pt)*this.zoom/2}px`};
        },
        positionInlineEditor() {
            if(!this.textEditing)return;
            const block=this.blocks.find(item=>String(item._key)===String(this.textEditing.key));
            if(this.$refs?.inlineTextHost)Object.assign(this.$refs.inlineTextHost.style,this.inlineEditorStyle());
            if(block && this.textEditing.element)Object.assign(this.textEditing.element.style,this.textEditorStyle(block));
        },
        mountTextEditor() {
            if(this.inlineMountTimer!==null)clearTimeout(this.inlineMountTimer);this.inlineMountTimer=null;
            const editing=this.textEditing;if(!editing || this.destroyed)return;
            const block=this.blocks.find(item=>String(item._key)===String(editing.key));
            const {node,component,editor}=this.nativeInlineEditor();
            if(!block || !editor || editor.isDestroyed || this.inspectorHydrating){this.inlineMountTimer=setTimeout(()=>this.mountTextEditor(),40);return;}
            for(const toolbar of this.$refs.inlineTextHost?.querySelectorAll('.fi-fo-rich-editor-floating-toolbar') || []){
                toolbar.dataset.canvasInlineToolbar=String(editing.key);
                toolbar.classList.add('canvas-inline-editor-toolbar');
            }
            if(editing.element===editor.view.dom){this.positionInlineEditor();this.syncPreview();return;}
            const contents=block.data.properties || {};
            const value=contents.rich_text || plainDocument(this.text(block));
            editing.original=JSON.stringify(contents);node.dataset.canvasHydrating='true';node.dataset.canvasElementKey=String(editing.key);
            editor.commands.setContent(value,{emitUpdate:false});
            const state=editor.state;
            if(state?.constructor?.create && editor.view?.updateState)editor.view.updateState(state.constructor.create({doc:state.doc,plugins:state.plugins}));
            component.shouldUpdateState=false;component.state=editor.getJSON();
            editing.document=JSON.parse(JSON.stringify(editor.getJSON()));editing.html=editor.getHTML();editing.element=editor.view.dom;
            editing.element.classList.add('canvas-rich-text');
            this.positionInlineEditor();this.syncPreview();
            this.$nextTick?.(()=>{delete node.dataset.canvasHydrating;if(this.textEditing?.key===editing.key && this.textEditing?.element===editor.view.dom){editor.commands.focus('end',{scrollIntoView:false});}});
        },
        inputText(detail) {
            const editing=this.textEditing;
            if(!editing || String(detail?.key)!==String(editing.key) || detail?.document?.type!=='doc')return;
            editing.document=JSON.parse(JSON.stringify(detail.document));editing.html=renderRichDocument(editing.document,this.richTextColors);this.schedulePreview();
        },
        textEditorKey(event) {
            if(!this.textEditing)return;
            event.stopPropagation();
            if(event.key==='Escape'){event.preventDefault();this.cancelTextEditing();}
            if((event.metaKey||event.ctrlKey)&&event.key==='Enter'){event.preventDefault();this.commitTextEditing();}
        },
        commitTextEditing() {
            const editing=this.textEditing;if(!editing)return;
            const block=this.blocks.find(item=>String(item._key)===String(editing.key));
            const {node,editor}=this.nativeInlineEditor();
            const document=editor && editing.element===editor.view.dom?JSON.parse(JSON.stringify(editor.getJSON())):editing.document;
            this.textEditing=null;if(this.inlineMountTimer!==null)clearTimeout(this.inlineMountTimer);this.inlineMountTimer=null;
            if(node)node.dataset.canvasElementKey='';
            if(block && document?.type==='doc'){
                const original=JSON.parse(editing.original),html=renderRichDocument(document,this.richTextColors);
                const previous=original.rich_text?.type==='doc'?renderRichDocument(original.rich_text,this.richTextColors):escapeHtml(original.text ?? this.text(block)).replace(/\n/g,'<br>');
                if(html!==previous){this.checkpoint();block.data.properties={...original,rich_text:document,html,text:plainRichDocument(document)};}
            }
            editor?.commands.blur();this.syncPreview();this.syncInspector();
        },
        resumeInlineEditing(detail) {
            if(!this.textEditing || !String(detail?.key ?? '').startsWith('inlineTextForm.'))return;
            const key=this.textEditing.key;
            if(this.inlineFocusTimer!==null)clearTimeout(this.inlineFocusTimer);
            // Native action dialogs restore their trigger's focus after the closing transition.
            this.inlineFocusTimer=setTimeout(()=>{this.inlineFocusTimer=null;if(!this.destroyed && this.textEditing?.key===key)this.nativeInlineEditor().editor?.commands.focus(null,{scrollIntoView:false});},350);
        },
        finishInlineEditing(event) {
            if(!this.textEditing || event.target?.closest?.('[data-canvas-inline-editor-host],[data-canvas-inline-toolbar],.fi-modal,[role="dialog"]'))return;
            this.commitTextEditing();
        },
        cancelTextEditing() {
            if(!this.textEditing)return;
            this.textEditing=null;if(this.inlineMountTimer!==null)clearTimeout(this.inlineMountTimer);this.inlineMountTimer=null;
            const {node,editor}=this.nativeInlineEditor();if(node)node.dataset.canvasElementKey='';
            editor?.commands.blur();this.syncPreview();this.syncInspector();
        },
        reorderLayers(keys) {
            if(this.saving || !Array.isArray(keys) || keys.length!==this.blocks.length)return;
            const ordered=keys.map(key=>this.blocks.find(block=>String(block._key)===String(key)));
            if(ordered.some(block=>!block)||new Set(ordered).size!==this.blocks.length)return;
            ordered.reverse(); // The layer list displays the highest painted layer first.
            if(ordered.every((block,index)=>block===this.blocks[index]))return;
            this.commitTextEditing();const selectedKey=this.current?._key;
            this.checkpoint();this.blocks=ordered;this.selected=selectedKey===undefined?null:this.blocks.findIndex(block=>block._key===selectedKey);this.cropIndex=null;this.syncPreview();
        },
        restoreSortable(container,keys) {
            if(!container?.querySelectorAll)return;
            const nodes=[...container.querySelectorAll(':scope > [x-sortable-item]')];
            for(const key of keys){const node=nodes.find(node=>String(node.getAttribute('x-sortable-item'))===String(key));if(node)container.appendChild(node);}
        },
        sortLayers(event) {
            const container=event.to || event.target, keys=container?.sortable?.toArray?.();
            if(!keys)return;
            const original=[...this.blocks].reverse().map(block=>block._key);
            // Filament's Sortable onEnd callback also moves nodes. Finish that callback
            // before restoring the DOM Alpine last rendered and changing its x-for data.
            queueMicrotask(()=>{if(this.destroyed)return;this.restoreSortable(container,original);this.reorderLayers(keys);});
        },
        editCrop(e,i) {
        if(this.saving||!this.imageTypes.includes(this.blocks[i].type))return;
        e.preventDefault(); e.stopPropagation(); this.selected=i; this.snapGuides=[]; this.checkpoint();
        const d=this.blocks[i].data;
        d.properties={...(d.properties||{}),fit:d.properties?.fit||'contain',crop_x:d.properties?.crop_x??.5,crop_y:d.properties?.crop_y??.5};
        if(!d.properties.image_transform){
            const img=this.previewNodes.get(this.blocks[i]._key)?.querySelector('img')||e.currentTarget?.querySelector('img');
            const iw=img?.naturalWidth||Number(d.width_pt), ih=img?.naturalHeight||Number(d.height_pt);
            const scale=(d.properties.fit==='cover'?Math.max:Math.min)(d.width_pt/iw,d.height_pt/ih);
            d.properties.image_transform={x:(d.width_pt-iw*scale)*d.properties.crop_x,y:(d.height_pt-ih*scale)*d.properties.crop_y,width:iw*scale,height:ih*scale,rotation:0};
        }
        this.cropIndex=i;
        this.syncPreview();
    },
        imageStyle(b, includeFlip=true) {
        const c=b.data.properties||{},t=c.image_transform;
        const flip=includeFlip?` scale(${c.flip_x?-1:1},${c.flip_y?-1:1})`:'';
        if(t)return {position:'absolute',left:t.x+'px',top:t.y+'px',width:t.width+'px',height:t.height+'px',maxWidth:'none',objectFit:'fill',transform:`rotate(${-t.rotation}deg)${flip}`,transformOrigin:'center'};
        return {objectFit:c.fit==='cover'?'cover':'contain',objectPosition:`${(c.crop_x??.5)*100}% ${(c.crop_y??.5)*100}%`,transform:includeFlip?`scale(${c.flip_x?-1:1},${c.flip_y?-1:1})`:''};
    },
        flipImage(axis) { if(!this.imageTypes.includes(this.current?.type)||!['x','y'].includes(axis))return;this.checkpoint();const c=this.current.data.properties;c['flip_'+axis]=!c['flip_'+axis]; },
        setFontSize(value) { const size=Number(value);if(!this.isText(this.current)||!Number.isFinite(size)||size<1||size>100)return;this.checkpoint();this.current.data.properties.font_size=size; },
        resizeGeometry(d,dx,dy,corner='se',modifiers={}) {
        const a=-Number(d.rotation_deg)*Math.PI/180, sx=corner.includes('w')?-1:1, sy=corner.includes('n')?-1:1;
        const lx=dx*Math.cos(a)+dy*Math.sin(a),ly=-dx*Math.sin(a)+dy*Math.cos(a);
        const snap=v=>this.snap(v);
        const width=Number(d.width_pt),height=Number(d.height_pt),factor=modifiers.altKey?2:1;
        let w=Math.max(1,snap(width+sx*lx*factor)),h=Math.max(1,snap(height+sy*ly*factor));
        if(modifiers.shiftKey){
            const scale=Math.max(1/width,1/height,Math.abs(w/width-1)>=Math.abs(h/height-1)?w/width:h/height);
            w=width*scale;h=height*scale;
        }
        const dw=w-Number(d.width_pt),dh=h-Number(d.height_pt);
        if(modifiers.altKey)return {x_pt:Number(d.x_pt)-dw/2,y_pt:Number(d.y_pt)-dh/2,width_pt:w,height_pt:h};
        return {x_pt:Number(d.x_pt)+(sx*dw*Math.cos(a)-sy*dh*Math.sin(a)-dw)/2,y_pt:Number(d.y_pt)+(sx*dw*Math.sin(a)+sy*dh*Math.cos(a)-dh)/2,width_pt:w,height_pt:h};
    },
        key(e) {
        if(this.drawingSession && ['Enter','Escape'].includes(e.key)){e.preventDefault();e.stopPropagation();e.key==='Escape'?this.cancelDrawing():this.finishDrawing();return;}
        if(this.textEditing){if(e.key==='Escape'){e.preventDefault?.();e.stopPropagation?.();this.cancelTextEditing();}return;}
        if(e.target?.closest?.('input,textarea,select,button,[role="slider"],[contenteditable="true"]')||this.saving)return;
        if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='z'){e.preventDefault();e.shiftKey?this.redo():this.undo();return;}
        if(e.key==='Escape'){this.cropIndex=null;this.endGesture();return;}
        if((e.key==='Delete'||e.key==='Backspace')&&this.current){e.preventDefault();e.stopPropagation();this.remove();this.cropIndex=null;}
    },
        setZoom(value) {
            const stage=this.$refs.stage;
            if(!stage)return;
            const rect=stage.getBoundingClientRect();
            this.zoomAt(Number(value),rect.left+(stage.clientLeft||0)+stage.clientWidth/2,rect.top+(stage.clientTop||0)+stage.clientHeight/2);
        },
        zoomAt(value, clientX, clientY) {
            const stage=this.$refs.stage,paper=this.$refs.paper;
            if(!stage||!paper||this.gesture||this.saving||this.destroyed)return;
            const next=Math.max(this.minZoom,Math.min(this.maxZoom,Number(value)));
            if(!Number.isFinite(next)||next===this.zoom||!Number.isFinite(clientX)||!Number.isFinite(clientY))return;
            const rect=stage.getBoundingClientRect();
            const localX=clientX-rect.left-(stage.clientLeft||0),localY=clientY-rect.top-(stage.clientTop||0);
            // Keep geometry in document coordinates; measuring the rounded DOM each time adds drift.
            const x=(localX-this.viewport.x+stage.scrollLeft)/this.zoom,y=(localY-this.viewport.y+stage.scrollTop)/this.zoom;
            this.placeViewport(next,localX-x*next,localY-y*next);
        },
        wheel(e) {
        e.preventDefault(); e.stopPropagation();
        if(this.saving||this.gesture||this.nativePinch)return;
        const stage=this.$refs.stage;
        const unit=e.deltaMode===1?16:e.deltaMode===2?stage.clientHeight:1;
        if(e.ctrlKey) {
            this.zoomAt(this.zoom*Math.exp(-e.deltaY*unit*.01),e.clientX,e.clientY);
        } else {
            stage.scrollLeft+=(e.shiftKey&&!e.deltaX?e.deltaY:e.deltaX)*unit;
            stage.scrollTop+=(e.shiftKey&&!e.deltaX?0:e.deltaY)*unit;
        }
    },
        pinchStart(e) {
        e.preventDefault(); e.stopPropagation();
        if(this.gesture||this.saving)return;
        const r=this.$refs.stage.getBoundingClientRect();
        this.nativePinch={zoom:this.zoom,x:Number.isFinite(e.clientX)?e.clientX:r.left+r.width/2,y:Number.isFinite(e.clientY)?e.clientY:r.top+r.height/2};
    },
        pinchChange(e) {
        e.preventDefault(); e.stopPropagation();
        if(!this.nativePinch)return;
        this.zoomAt(this.nativePinch.zoom*e.scale,this.nativePinch.x,this.nativePinch.y);
    },
        pinchEnd(e) { e.preventDefault(); e.stopPropagation(); this.nativePinch=null; },
        drawingDefaults() { return {x1:0,y1:50,x2:100,y2:50,stroke:'#E60014',stroke_width:0.7,pattern:'solid',dash_length:4.25,gap_length:4.25}; },
        drawingOrientation() {
        const d=this.current?.data.properties?.drawing||{};
        if(Number(d.y1)===Number(d.y2))return 'horizontal';
        if(Number(d.x1)===Number(d.x2))return 'vertical';
        if(Number(d.x1)===0&&Number(d.x2)===100&&Number(d.y1)===0&&Number(d.y2)===100)return 'descending';
        if(Number(d.x1)===0&&Number(d.x2)===100&&Number(d.y1)===100&&Number(d.y2)===0)return 'ascending';
        return 'custom';
    },
        setDrawingOrientation(value) {
        const points={horizontal:{x1:0,y1:50,x2:100,y2:50},vertical:{x1:50,y1:0,x2:50,y2:100},descending:{x1:0,y1:0,x2:100,y2:100},ascending:{x1:0,y1:100,x2:100,y2:0}}[value];
        if(this.current?.type!=='line'||!points)return;
        this.checkpoint();Object.assign(this.current.data.properties.drawing,points);
    },
        get current() { return this.selected === null ? null : this.blocks[this.selected]; },
        snapshot() { return JSON.stringify(this.blocks); },
        checkpoint(snapshot=this.snapshot()) { this.undoStack.push(snapshot); if (this.undoStack.length > 60) this.undoStack.shift(); this.redoStack = []; this.dirty = true; },
        undo() { if(this.drawingSession){this.cancelDrawing();return;}this.endGesture();if(this.textEditing)this.commitTextEditing();if (!this.undoStack.length) return; this.redoStack.push(this.snapshot()); this.blocks = JSON.parse(this.undoStack.pop()); this.prepare(); this.selected = null; this.cropIndex=null; this.inspectorInvalid=false; this.error=''; this.dirty = true; this.syncInspector(); },
        redo() { this.endGesture();if(this.textEditing)this.commitTextEditing();if (!this.redoStack.length) return; this.undoStack.push(this.snapshot()); this.blocks = JSON.parse(this.redoStack.pop()); this.prepare(); this.selected = null; this.cropIndex=null; this.inspectorInvalid=false; this.error=''; this.dirty = true; this.syncInspector(); },
        normalize(b) { const d = b.data; d.width_pt = Math.max(1, Math.min(this.pageWidth, Number(d.width_pt)||1)); d.height_pt = Math.max(1, Math.min(this.pageHeight, Number(d.height_pt)||1)); d.x_pt = Math.max(0, Math.min(this.pageWidth-d.width_pt, Number(d.x_pt)||0)); d.y_pt = Math.max(0, Math.min(this.pageHeight-d.height_pt, Number(d.y_pt)||0)); d.rotation_deg = Math.max(-360, Math.min(360, Number(d.rotation_deg)||0)); },
        duplicate() { if(this.drawingSession && !this.finishDrawing())return;if(this.textEditing)this.commitTextEditing();if (!this.current) return; this.checkpoint(); const b=JSON.parse(JSON.stringify(this.current)); b._key=this.nextKey++; delete b.data.id; b.data.name+=' (copy)'; b.data.x_pt=Number(b.data.x_pt)+12; b.data.y_pt=Number(b.data.y_pt)+12; this.normalize(b); this.blocks.push(b); this.selected=this.blocks.length-1; },
        remove() { if(this.drawingSession?.creating){this.cancelDrawing();return;}if(this.drawingSession)this.finishDrawing();if(this.textEditing)this.commitTextEditing();if (!this.current) return; this.checkpoint(); this.blocks.splice(this.selected,1); this.selected=null; this.cropIndex=null; },
        layer(delta) { if(this.drawingSession && !this.finishDrawing())return;const i=this.selected, j=i+delta; if(i===null||j<0||j>=this.blocks.length)return; this.checkpoint(); [this.blocks[i],this.blocks[j]]=[this.blocks[j],this.blocks[i]]; this.selected=j; this.cropIndex=null; },
        start(e,i,mode,corner='se') { if(e.button!==0||this.nativePinch||this.saving||this.isDrawingEditing(this.blocks[i])||this.isTextEditing(this.blocks[i])||e.target?.closest?.('[data-canvas-text-editor]'))return;if(this.textEditing)this.commitTextEditing(); if(this.drawingSession && this.drawingSession.key!==this.blocks[i]._key)this.finishDrawing();e.preventDefault(); this.snapGuides=[]; this.selected=i; e.currentTarget.focus?.({preventScroll:true}); if(this.cropIndex!==i)this.cropIndex=null; if(this.cropIndex===i&&mode==='move')mode='crop'; const snapshot=this.snapshot(); const d=this.current.data; const r=this.$refs.paper.getBoundingClientRect(); this.gesture={mode,corner,snapshot,changed:false,x:e.clientX,y:e.clientY,d:JSON.parse(JSON.stringify(d)),cx:r.left+(Number(d.x_pt)+Number(d.width_pt)/2)*this.zoom,cy:r.top+(Number(d.y_pt)+Number(d.height_pt)/2)*this.zoom};
        if(this.cropIndex===i){
            const t=d.properties.image_transform;
            this.gesture.mode='image-'+(mode==='crop'?'move':mode);
            const a=-Number(d.rotation_deg)*Math.PI/180;
            const ox=Number(t.x)+Number(t.width)/2-Number(d.width_pt)/2,oy=Number(t.y)+Number(t.height)/2-Number(d.height_pt)/2;
            this.gesture.cx+=(ox*Math.cos(a)-oy*Math.sin(a))*this.zoom;
            this.gesture.cy+=(ox*Math.sin(a)+oy*Math.cos(a))*this.zoom;
        }
        this.gesture.angle=Math.atan2(e.clientY-this.gesture.cy,e.clientX-this.gesture.cx); e.currentTarget.setPointerCapture(e.pointerId); },
        checkpointGesture() {
            const gesture=this.gesture;
            if(!gesture || gesture.changed || JSON.stringify(this.current?.data)===JSON.stringify(gesture.d))return;
            this.checkpoint(gesture.snapshot ?? this.snapshot());gesture.changed=true;
        },
        move(e) { if(!this.gesture||!this.current)return; const g=this.gesture,d=this.current.data,dx=(e.clientX-g.x)/this.zoom,dy=(e.clientY-g.y)/this.zoom; const snap=v=>this.snap(v);this.snapGuides=[];
        if(g.mode==='drawing-point'){
            const angle=-Number(g.d.rotation_deg || 0)*Math.PI/180,point=g.d.properties.drawing.points[g.point];
            d.properties.drawing.points[g.point]={x:Math.max(0,Math.min(100,point.x+(dx*Math.cos(angle)+dy*Math.sin(angle))/Number(d.width_pt)*100)),y:Math.max(0,Math.min(100,point.y+(-dx*Math.sin(angle)+dy*Math.cos(angle))/Number(d.height_pt)*100))};
            this.checkpointGesture();return;
        }
        if(g.mode.startsWith('image-')){
            const old=g.d.properties.image_transform,t=d.properties.image_transform;
            const a=-Number(g.d.rotation_deg)*Math.PI/180,lx=dx*Math.cos(a)+dy*Math.sin(a),ly=-dx*Math.sin(a)+dy*Math.cos(a);
            if(g.mode==='image-move'){t.x=Number(old.x)+lx;t.y=Number(old.y)+ly;}
            if(g.mode==='image-resize'){
                const next=this.resizeGeometry({x_pt:old.x,y_pt:old.y,width_pt:old.width,height_pt:old.height,rotation_deg:old.rotation},lx,ly,g.corner,e);
                Object.assign(t,{x:next.x_pt,y:next.y_pt,width:next.width_pt,height:next.height_pt});
            }
            if(g.mode==='image-rotate'){t.rotation=Number(old.rotation)-(Math.atan2(e.clientY-g.cy,e.clientX-g.cx)-g.angle)*180/Math.PI;if(e.shiftKey)t.rotation=Math.round(t.rotation/15)*15;t.rotation=((t.rotation+540)%360)-180;}
            this.checkpointGesture();this.syncPreview();
            return;
        }
        if(g.mode==='move'){
            const raw={...d,x_pt:Number(g.d.x_pt)+dx,y_pt:Number(g.d.y_pt)+dy};
            d.x_pt=snap(raw.x_pt);d.y_pt=snap(raw.y_pt);
            if(this.alignmentSnap && !e.ctrlKey && !e.metaKey){
                const aligned=alignMove(raw,this.blocks,this.current._key,this.pageWidth,this.pageHeight,this.zoom);
                for(const guide of aligned.guides){d[guide.axis==='x'?'x_pt':'y_pt']=aligned.geometry[guide.axis==='x'?'x_pt':'y_pt'];}
                this.snapGuides=aligned.guides;
            }
        }
        if(g.mode==='resize'){
            let next=this.resizeGeometry(g.d,dx,dy,g.corner,e);
            if(this.alignmentSnap && !e.ctrlKey && !e.metaKey){
                const aligned=alignResize(next,g.d,g.corner,e,this.blocks,this.current._key,this.pageWidth,this.pageHeight,this.zoom);
                next=aligned.geometry;this.snapGuides=aligned.guides;
            }
            if(next.x_pt>=0&&next.y_pt>=0&&next.x_pt+next.width_pt<=this.pageWidth&&next.y_pt+next.height_pt<=this.pageHeight)Object.assign(d,next);
        }
        if(g.mode==='rotate'){d.rotation_deg=Number(g.d.rotation_deg)-(Math.atan2(e.clientY-g.cy,e.clientX-g.cx)-g.angle)*180/Math.PI;if(e.shiftKey)d.rotation_deg=Math.round(d.rotation_deg/15)*15;}
        this.normalize(this.current);this.checkpointGesture();
        const bounds=elementBounds(d);
        this.snapGuides=this.snapGuides.filter(guide=>bounds[guide.axis].some(value=>Math.abs(value-guide.position)<.00001));
    },
        style(b,i) { const d=b.data;return {left:d.x_pt+'px',top:d.y_pt+'px',width:d.width_pt+'px',height:d.height_pt+'px',transform:`rotate(${-Number(d.rotation_deg)}deg)`,zIndex:i+1,opacity:d.visible===false?.25:1,color:d.properties?.color||'#222',cursor:this.drawingSession?.creating?'crosshair':'move',fontSize:(d.properties?.font_size||(b.type==='table'?8:12))+'px',textAlign:d.properties?.align||'center'}; },
        onPreviewLoad() {
        if (this.destroyed || !this.$refs.preview?.srcdoc) return;
        const doc=this.$refs.preview.contentDocument,page=doc?.querySelector('.page');
        if (!page) return;
        this.syncPreviewFonts(doc);
        for(const node of this.previewNodes.values())if(node._canvasScope)globalThis.Alpine?.destroyTree(node);
        this.previewNodes=new Map(); this.previewSignatures=new Map();
        const first=page.querySelector('.block[data-editor-index]');
        this.previewAnchor=doc.createComment('canvas-editor-blocks');
        page.insertBefore(this.previewAnchor,first||page.querySelector('.chrome')||null);
        for (const node of page.querySelectorAll('.block[data-editor-index]')) {
            const block=this.blocks[Number(node.dataset.editorIndex)];
            if (!block) continue;
            this.previewNodes.set(block._key,node);
        }
        this.previewReady=true;this.schedulePreview();
        doc.fonts.ready.then(()=>{ if(!this.destroyed && this.$refs.preview?.contentDocument===doc)this.previewLoaded=true; });
    },
        syncPreviewFonts(document) {
            if(!this.$el?.ownerDocument?.createElement)return;
            const fontRules=[];
            const collect=rules=>{for(const rule of rules){if(fontRules.length>=32)return;if(rule.type===5)fontRules.push(rule.cssText);else if(rule.cssRules)collect(rule.cssRules);}};
            for(const sheet of document.styleSheets || []){try{collect(sheet.cssRules);}catch{/* External stylesheets may deny access to their rules. */}}
            if(!fontRules.length){this.previewFontStyle?.remove();this.previewFontStyle=null;return;}
            if(!this.previewFontStyle){this.previewFontStyle=this.$el.ownerDocument.createElement('style');this.previewFontStyle.dataset.canvasPreviewFonts='';this.$el.prepend(this.previewFontStyle);}
            const css=fontRules.join('\n');
            if(this.previewFontStyle.textContent!==css)this.previewFontStyle.textContent=css;
        },
        escape(value) { return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); },
        syncPreview() {
        if (this.destroyed || !this.previewReady||!this.previewAnchor?.isConnected) return;
        const doc=this.$refs?.preview?.contentDocument;
        if(!doc || doc!==this.previewAnchor.ownerDocument)return;
        const visible=[],active=new Set();
        this.blocks.forEach((b,index)=>{
            const key=b._key;active.add(key);
            let node=this.previewNodes.get(key);
            if (!node) { node=doc.createElement('div');this.previewNodes.set(key,node); }
            node.className='block'+(this.isText(b)?' text':'')+(b.data.properties?.index_icon?' index-icon':'');
            node.dataset.kind=b.type;node.dataset.editorIndex=index;
            const d=b.data,c=d.properties||{};
            Object.assign(node.style,{
                left:Number(d.x_pt)+'pt',top:Number(d.y_pt)+'pt',width:Number(d.width_pt)+'pt',height:Number(d.height_pt)+'pt',
                transform:`rotate(${-Number(d.rotation_deg||0)}deg)`,fontSize:Number(c.font_size??(b.type==='title'?14:b.type==='text'?9:8))+'pt',
                color:/^#[a-f\d]{3,8}$/i.test(c.color??'')?(c.color):'#111111',textAlign:c.rich_text?.type==='doc'?'left':['left','center','right','justify'].includes(c.align)?(c.align):'center',
                opacity:String(c.opacity??1),mixBlendMode:['normal','multiply','screen'].includes(c.blend_mode)?c.blend_mode:'normal',fontFamily:'',visibility:''
            });
            const signature=this.contentSignature(b);
            if (this.previewSignatures.get(key)!==signature) {
                if(!Object.hasOwn(this.templates,b.type)){this.error=this.messages.missing_template.replace(':type', b.type);return;}
                if(!node._canvasScope || node._canvasTemplate!==this.templates[b.type]){
                    Alpine.destroyTree(node);
                    node._canvasTemplate=this.templates[b.type];
                    node.innerHTML=this.templates[b.type];
                    node._canvasScope=Alpine.reactive({data:this.viewData(b)});
                    Alpine.addScopeToNode(node,node._canvasScope);
                    Alpine.initTree(node);
                }else node._canvasScope.data=this.viewData(b);
                this.previewSignatures.set(key,signature);
            }
            const text=node.querySelector('.canvas-rich-text'),editing=this.isTextEditorReady(b);
            if(text)text.style.visibility=editing?'hidden':'';
            else node.style.visibility=editing?'hidden':'';
            if (d.visible!==false) visible.push(node); else node.remove();
        });
        for (const [key,node] of this.previewNodes) if (!active.has(key)) {if(node._canvasScope)globalThis.Alpine?.destroyTree(node);node.remove();this.previewNodes.delete(key);this.previewSignatures.delete(key);}
        this.previewAnchor.after(...visible);
    },
        async save(wire) {
        if(this.saving||this.imageUploading)return;
        if(this.drawingSession && !this.finishDrawing())return;
        if(this.textEditing)this.commitTextEditing();
        await this.flushInspector();
        if(this.inspectorInvalid)return;
        this.saving=true;this.error='';this.endGesture();
        try{
            const keys=this.blocks.map(b=>b._key),payload=JSON.parse(this.snapshot());
            for(const b of payload){delete b._key;if(b.data.image_id==='')b.data.image_id=null;}
            const saved=await wire.save(payload);
            if(this.destroyed)return;
            // The builder returns the saved layer order, including IDs for new elements.
            // Keep preview identities so saving does not replace loaded image nodes.
            this.blocks=saved.map((b,index)=>({...b,_key:keys[index]}));
            this.prepare();this.syncPreview();
            this.dirty=false;this.undoStack=[];this.redoStack=[];
        }catch(e){this.error=this.messages.save_error;}
        finally{this.saving=false;}
    },
        prepare() {
            for (const b of this.blocks) {
                b._key ??= this.nextKey++;
                this.nextKey = Math.max(this.nextKey, Number(b._key) + 1);
                b.data.properties ??= {};
                if (b.type === 'line') b.data.properties.drawing = {...this.drawingDefaults(), ...(b.data.properties.drawing || {})};
            }
        },
        add(type) {
            if(this.drawingSession && !this.finishDrawing())this.cancelDrawing();
            if(this.saving || this.adding || !Object.hasOwn(this.labels,type))return null;
            const insert=initialized=>{
                if(this.destroyed)return null;
                const before={snapshot:this.snapshot(),undoDepth:this.undoStack.length,dirty:this.dirty,redo:[...this.redoStack]};
                this.checkpoint();
                const data={
                    name:this.labels[type],x_pt:Math.min(40,this.pageWidth/10),y_pt:Math.min(40,this.pageHeight/10),
                    width_pt:Math.min(200,this.pageWidth),height_pt:Math.min((this.elementTypes[type]?.kind==='line' || type==='line')?12:100,this.pageHeight),
                    rotation_deg:0,visible:true,image_id:null,references:[],properties:{},...initialized?.data,
                };
                const block={_key:this.nextKey++,type,data};
                this.blocks.push(block);this.selected=this.blocks.length-1;
                this.normalize(block);this.prepare();
                if(this.isDrawing(block))this.beginDrawing(block,before);
                else this.syncInspector();
                this.schedulePreview();
                return block;
            };
            // Server schemas are the sole source of initial values in a mounted editor.
            if(!this.$wire?.createElement)return insert({data:{properties:{}}});
            this.adding=true;
            return this.$wire.createElement(type).then(insert).catch(()=>{this.error=this.messages.create_error;return null;}).finally(()=>{this.adding=false;});
        },
        image(b) { const first=this.mediaCollection(b),rendered=this.previewNodes.get(b._key)?.querySelector('[data-canvas-image]');return this.safeImageUrl(this.mediaImages(b,first?.name)[0]?.image ?? (rendered ? rendered.getAttribute('src') || '' : null) ?? b.data.image_url ?? b.data.properties?.image_url ?? ''); },
        safeImageUrl(value) { const url=String(value || ''); return (/^https?:/i.test(url) || url.startsWith('blob:') || (url.startsWith('/') && !url.startsWith('//')) || new RegExp('^data:image/(?:png|jpeg|webp|gif);base64,', 'i').test(url)) ? url : ''; },
        text(b) { return this.previewNodes.get(b._key)?.querySelector('[data-canvas-text]')?.textContent ?? b.data.properties?.text ?? ''; },
        contentSignature(b) { const {id, ...data} = b.data; return JSON.stringify([b.type, data,this.isTextEditing(b)?this.textEditing.html:null]); },
        viewData(b) {
            const c=b.data.properties || {},d=b.data;
            const media=Object.fromEntries(this.mediaCollections(b).map(collection=>[collection.name,collection.max_files>1?this.mediaImages(b,collection.name):(this.mediaImages(b,collection.name)[0] || {})]));
            const data={...this.documentData,...c,type:b.type,geometry:{x:Number(d.x_pt),y:Number(d.y_pt),width:Number(d.width_pt),height:Number(d.height_pt),rotation:Number(d.rotation_deg || 0)},media,
                text:c.text ?? '',html:this.textHtml(b),image_url:this.safeImageUrl(this.image(b)),columns:c.columns ?? [],rows:c.rows ?? []};
            try {data.link_url=['http:', 'https:'].includes(new URL(c.link_url).protocol)?c.link_url:'';} catch {data.link_url='';}
            data.text_style={textAlign:'left',fontWeight:400,fontStyle:'normal',lineHeight:Math.max(.5,Math.min(10,Number(c.leading ?? 1.25))),columnCount:Math.max(1,Math.min(12,Math.trunc(Number(c.columns ?? 1)))),backgroundColor:/^#[a-f\d]{3,8}$/i.test(c.background_color || '')?c.background_color:'transparent',padding:Math.max(0,Math.min(100,Number(c.padding)||0))+'pt',borderRadius:Math.max(0,Math.min(100,Number(c.border_radius)||0))+'pt'};
            const t=c.image_transform;
            data.image_style=t?{position:'absolute',left:Number(t.x)+'pt',top:Number(t.y)+'pt',width:Number(t.width)+'pt',height:Number(t.height)+'pt',maxWidth:'none',objectFit:'fill',transform:`rotate(${-Number(t.rotation)}deg) scale(${c.flip_x?-1:1},${c.flip_y?-1:1})`}:{objectFit:c.fit==='cover'?'cover':'contain',objectPosition:`${Number(c.crop_x??.5)*100}% ${Number(c.crop_y??.5)*100}%`,transform:`scale(${c.flip_x?-1:1},${c.flip_y?-1:1})`};
            const drawing=c.drawing || {},number=(key,fallback,min,max)=>drawing[key]!=null&&drawing[key]!==''&&Number.isFinite(Number(drawing[key]))?Math.max(min,Math.min(max,Number(drawing[key]))):fallback;
            data.line={x1:number('x1',0,0,100)*data.geometry.width/100,y1:number('y1',50,0,100)*data.geometry.height/100,x2:number('x2',100,0,100)*data.geometry.width/100,y2:number('y2',50,0,100)*data.geometry.height/100,stroke:/^#[a-f\d]{3,6}$/i.test(drawing.stroke || '')?drawing.stroke:'#E60014',weight:number('stroke_width',.7,.1,20),dashes:drawing.pattern==='dotted'?`0 ${number('gap_length',4.25,.1,100)}`:drawing.pattern==='dashed'?`${number('dash_length',4.25,.1,100)} ${number('gap_length',4.25,.1,100)}`:'none',cap:drawing.pattern==='dotted'?'round':'butt'};
            return data;
        },
        destroy() { this.endGesture();if(this.inlineFocusTimer!==null)clearTimeout(this.inlineFocusTimer);this.inlineFocusTimer=null;if(this.inlineMountTimer!==null)clearTimeout(this.inlineMountTimer);this.inlineMountTimer=null;this.destroyed=true;this.disposeZoomSlider?.();this.disposeZoomSlider=null;this.disposeEditorViewport?.();this.disposeEditorViewport=null; this.previewReady=false; this.gesture=null; this.textEditing=null;this.previewFontStyle?.remove();this.previewFontStyle=null;if(this.previewFrame !== null)cancelAnimationFrame(this.previewFrame); this.previewFrame=null; if(typeof this.disposeWatch === 'function')this.disposeWatch();if(typeof this.disposeSelectionWatch === 'function')this.disposeSelectionWatch(); this.disposeWatch=null;this.disposeSelectionWatch=null; if(typeof this.disposeInspectorWatch==='function')this.disposeInspectorWatch();for(const node of this.previewNodes.values())if(node._canvasScope)globalThis.Alpine?.destroyTree(node);this.previewNodes.clear(); this.previewSignatures.clear(); },
    };
    return state;
}
