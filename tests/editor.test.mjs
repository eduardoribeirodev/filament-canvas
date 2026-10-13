import { default as assert } from 'node:assert/strict';
import { default as test } from 'node:test';
import { default as createCanvas, canonicalElementData, renderRichDocument, plainRichDocument } from '../resources/dist/canvas.js';

// canvas-package.test.mjs
{
    function editor(options = {}) {
        return createCanvas([], [], 841.89, 595.28, '', options);
    }

    test('generic tables render their own data and escape headings and cell content', () => {
        const canvas = editor();
        canvas.add('data-table');
        canvas.current.data.dados.columns = [{label: '<script>heading</script>', field: 'value'}];
        canvas.current.data.dados.rows = [{value: '<img src=x onerror=alert(1)>'}];

        const html = canvas.renderContent(canvas.current);
        assert.match(html, /&lt;script&gt;heading&lt;\/script&gt;/);
        assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
        assert.doesNotMatch(html, /<script>|<img/);
        assert.equal(canvas.products, undefined);
    });

    test('an extension preserves getters and custom element behavior through clone and undo', async () => {
        const canvas = editor({
            labels: {badge: 'Selo'},
            elementTypes: {
                badge: {
                    prepare(block) { block.data.dados.variant ||= 'blue'; },
                    render(block) { return `<strong>${this.escape(block.data.dados.text)}</strong>`; },
                },
            },
            extension(state) {
                return {
                    get selectedBadge() { return this.current?.type === 'badge' ? this.current : null; },
                    extensionWidth() { return state.pageWidth; },
                };
            },
        });

        canvas.$wire={createElement:async type=>({type,data:{dados:{text:'Especial',variant:'red'}}})};
        await canvas.add('badge');
        assert.equal(canvas.selectedBadge, canvas.current);
        assert.equal(canvas.extensionWidth(), 841.89);
        assert.equal(canvas.renderContent(canvas.current), '<strong>Especial</strong>');
        canvas.duplicate();
        canvas.current.data.dados.text = 'Cópia';
        assert.equal(canvas.blocks[0].data.dados.text, 'Especial');
        canvas.undo();
        assert.equal(canvas.blocks.length, 1);
        assert.equal(canvas.selectedBadge, null);
        canvas.selected = 0;
        assert.equal(canvas.selectedBadge.data.dados.text, 'Especial');
    });

    test('custom image types use the asset library and crop inside the existing frame', () => {
        const canvas = createCanvas([], [{id: 7, image: '/assets/photo.png'}], 841.89, 595.28, '', {
            labels: {portrait: 'Retrato'},
            imageTypes: ['portrait'],
        });
        canvas.add('portrait');
        const block = canvas.current;
        block.data.imagem_origem = 7;
        assert.match(canvas.renderContent(block), /src="\/assets\/photo.png"/);
        canvas.editCrop({preventDefault() {}, stopPropagation() {}, currentTarget: {querySelector: () => ({naturalWidth: 400, naturalHeight: 100})}}, 0);
        assert.equal(canvas.cropIndex, 0);
        assert.equal(block.data.largura_pt, 200);
        assert.equal(block.data.altura_pt, 100);
        assert.equal(block.data.dados.image_transform.height, 50);
    });

    test('generic image URLs accept local and HTTPS assets while rejecting script and protocol-relative sources', () => {
        const canvas = editor();
        for (const url of ['/media/photo.webp', 'https://example.test/photo.png', 'blob:preview', 'data:image/png;base64,YQ==']) {
            assert.equal(canvas.safeImageUrl(url), url);
        }
        for (const url of ['javascript:alert(1)', '//example.test/photo.png', 'data:text/html;base64,YQ==', 'data:image/svg+xml;base64,YQ==']) {
            assert.equal(canvas.safeImageUrl(url), '');
        }
    });

    test('a custom block keeps its content and assigned ID after saving through a host adapter', async () => {
        const canvas = editor({labels: {badge: 'Selo'}});
        canvas.add('badge');
        canvas.current.data.dados.text = 'Salvo';
        const key = canvas.current._key;
        let sent;

        await canvas.save({salvar: async (blocks) => {
            sent = blocks;
            return blocks.map(block => ({...block, data: {...block.data, id: 31}}));
        }});

        assert.equal(sent[0]._key, undefined);
        assert.equal(sent[0].data.dados.text, 'Salvo');
        assert.equal(canvas.current.data.id, 31);
        assert.equal(canvas.current._key, key);
        assert.equal(canvas.dirty, false);
    });

    test('the reusable editor does not add product blocks without registration', () => {
        const canvas = editor();
        canvas.add('products');
        assert.equal(canvas.blocks.length, 0);
        canvas.add('asset');
        assert.equal(canvas.blocks.length, 1);
        assert.equal(canvas.current.type, 'asset');
    });
}

// canvas-custom-renderers.test.mjs
{
    test('custom renderer identifiers use the element view instead of a generic renderer for the same type', () => {
        const canvas = createCanvas([{type: 'table', data: {dados: {columns: [{label: 'Código', field: 'sku'}], rows: []}}}], [], 500, 400, '', {
            elementTypes: {table: {local_renderer: 'custom-table', capabilities: {references: true}}},
        });
        canvas.prepare();
        const block = canvas.blocks[0];
        let scheduled;
        canvas.scheduleElementRender = value => {scheduled = value;};
        assert.equal(canvas.renderContent(block), '');
        canvas.serverRenders.set(block._key, {html: '<table class="custom-table"><tr><td>AT-01</td></tr></table>'});
        assert.match(canvas.renderContent(block), /class="custom-table"/);
        assert.equal(scheduled, block);
    });
}

// canvas-native-properties.test.mjs
{
    const block = (name, caption) => ({type:'card',data:{nome:name,x_pt:10,y_pt:10,largura_pt:100,altura_pt:50,rotacao_graus:0,visivel:true,dados:{caption,preferences:{color:'#111111',slots:[[1,3],[2]]},enabled:false,count:0}}});

    function editor() {
        const state=createCanvas([block('Primeiro','A'),block('Segundo','B')],[],500,400,'',{labels:{card:'Cartão'},elementTypes:{card:{local_renderer:false}}});
        state.prepare();state.selected=0;state.inspectorGeneration=1;state.inspectorSelection=String(state.current._key);
        state.inspectorFields=[{path:'caption'},{path:'preferences.color'},{path:'enabled'},{path:'count',numeric:true},{path:'items',list:true,simple:'value'}];
        state.$el={querySelector:selector=>selector==='[data-canvas-properties]'?{dataset:{canvasInspectorKey:String(state.current?._key??''),canvasInspectorGeneration:String(state.inspectorGeneration)}}:null};
        state.$nextTick=callback=>callback();
        return state;
    }

    test('the canonical data bridge keeps legacy precedence and false/zero while removing old JSON groups',()=>{
        assert.deepEqual(canonicalElementData({configuracoes:{color:'#000000',rule:true},conteudo:{text:'Título',color:'#222222'},estilo:{color:'#111111',rule:false,font_size:0,empty:null}}),{dados:{text:'Título',color:'#111111',rule:false,font_size:0}});
        assert.deepEqual(canonicalElementData({dados:{text:'Novo'},conteudo:{text:'Antigo'}}),{dados:{text:'Novo'}});
    });

    test('native properties project immediately, preserve hidden nested data and participate in undo',()=>{
        const state=editor();state.$wire={updateInspector:async()=>({stale:true})};
        state.queueInspectorChanges({caption:'Editado',preferences:{color:'#ff0000'},enabled:false,count:0,items:{first:{value:'A'},second:{value:'B'}}});
        clearTimeout(state.inspectorTimer);state.inspectorTimer=null;
        assert.equal(state.current.data.dados.caption,'Editado');
        assert.deepEqual(state.current.data.dados.preferences,{color:'#ff0000',slots:[[1,3],[2]]});
        assert.deepEqual(state.current.data.dados.items,['A','B']);
        assert.equal(state.current.data.dados.enabled,false);assert.equal(state.current.data.dados.count,0);
        state.selected=1;state.inspectorPending=null;
        assert.equal(state.blocks[0].data.dados.caption,'Editado','switching selection before the debounce must retain the typed property');
        assert.equal(state.current.data.dados.caption,'B');
        state.undo();assert.equal(state.blocks[0].data.dados.caption,'A');
        state.redo();assert.equal(state.blocks[0].data.dados.caption,'Editado');
    });

    test('a response for an old selection can normalize only its own unchanged block and shares the original undo step',()=>{
        const state=editor();state.$wire={updateInspector:async()=>({stale:true})};
        state.queueInspectorChanges({caption:'editado',preferences:{color:'#111111'},enabled:false,count:0});
        clearTimeout(state.inspectorTimer);state.inspectorTimer=null;
        const source=state.serializeBlock(state.current),key=String(state.current._key),revision=state.inspectorRequestRevision;
        state.selected=1;state.inspectorGeneration=2;
        state.applyInspectorPatch({key,generation:1,revision,patch:{caption:'EDITADO'},html:'<b>EDITADO</b>'},source);
        assert.equal(state.blocks[0].data.dados.caption,'EDITADO');assert.equal(state.current.data.dados.caption,'B');
        assert.equal(state.undoStack.length,1);
        state.undo();assert.equal(state.blocks[0].data.dados.caption,'A');
        state.applyInspectorPatch({key,generation:1,revision,patch:{caption:'Tarde'},html:'<b>Tarde</b>'},source);
        assert.equal(state.blocks[0].data.dados.caption,'A','an asynchronous response must not reverse an undo');
    });

    test('custom Blade views are used even when a type inherits the text capability',async()=>{
        const state=createCanvas([block('Primeiro','A')],[],500,400,'',{elementTypes:{card:{local_renderer:false,capabilities:{text:true}}}});
        state.prepare();let calls=0;
        state.$wire={renderElement:async payload=>{calls++;return `<section class="custom-card">${payload.data.dados.caption}</section>`;}};
        assert.equal(state.renderContent(state.blocks[0]),'');
        await new Promise(resolve=>setTimeout(resolve,350));
        assert.equal(state.renderContent(state.blocks[0]),'<section class="custom-card">A</section>');assert.equal(calls,1);
        state.destroy();
    });

    test('named media collections bind their own reference fields and enforce declared item counts',()=>{
        const state=createCanvas([],[{id:1,image:'/one.png'},{id:2,image:'/two.png'},{id:3,image:'/three.png'}],500,400,'',{labels:{card:'Cartão'},elementTypes:{card:{media_collections:{cover:{reference_field:'cover_asset',max_files:1},gallery:{reference_field:'gallery_assets',max_files:2}}}}});
        state.add('card');const key=state.current._key;
        state.selectAsset(1,key,'cover');state.selectAsset([2,3],key,'gallery');
        assert.equal(state.current.data.cover_asset,1);assert.deepEqual(state.current.data.gallery_assets,[2,3]);
        assert.equal(state.current.data.imagem_origem,null);
        state.selectAsset([1,2,3],key,'gallery');assert.deepEqual(state.current.data.gallery_assets,[2,3]);
        assert.deepEqual(state.mediaImages(state.current,'gallery').map(asset=>asset.id),[2,3]);
        assert.equal(Object.hasOwn(state.current.data.dados,'image_transform'),false);
        state.current.data.dados.image_transform={x:1,y:1,width:100,height:100,rotation:0};
        state.selectAsset(2,key,'cover');
        assert.equal(state.current.data.dados.image_transform,null,'selecting new media clears an existing crop');
    });

    test('native RichEditor hydration defaults do not mark an unchanged document as edited',()=>{
        const saved={type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Texto',marks:[{type:'highlight'}]}]}]};
        const native={type:'doc',content:[{type:'paragraph',attrs:{textAlign:'start'},content:[{type:'text',marks:[{type:'highlight',attrs:{color:null}}],text:'Texto'}]}]};
        const state=editor();state.current.type='text';state.current.data.dados={rich_text:saved,text:'Texto'};
        state.inspectorFields=[{path:'rich_text',rich:true}];state.$wire={updateInspector:async()=>({stale:true})};
        state.inspectorValueSignature=state.inspectorStateSignature({rich_text:saved});
        state.queueInspectorChanges({rich_text:native});
        state.applyRichText({document:native});
        assert.equal(state.dirty,false);assert.equal(state.undoStack.length,0);assert.equal(state.inspectorPending,null);
        const changed=structuredClone(native);changed.content[0].content[0].marks.push({type:'bold'});
        state.queueInspectorChanges({rich_text:changed});
        clearTimeout(state.inspectorTimer);state.inspectorTimer=null;
        assert.equal(state.dirty,true);assert.equal(state.current.data.dados.rich_text.content[0].content[0].marks.at(-1).type,'bold');
    });

    test('numeric native fields keep their schema type before a validation request or selection change',()=>{
        const state=editor();state.$wire={updateInspector:async()=>({stale:true})};
        state.queueInspectorChanges({caption:'A',preferences:{color:'#111111'},enabled:false,count:'11'});
        clearTimeout(state.inspectorTimer);state.inspectorTimer=null;
        assert.equal(state.current.data.dados.count,11);
        state.selected=1;assert.equal(state.blocks[0].data.dados.count,11);
    });

    test('the title bold toggle can explicitly disable the native title weight',()=>{
        const state=createCanvas([{type:'title',data:{dados:{text:'Título',bold:false}}}]);state.prepare();
        assert.match(state.renderContent(state.blocks[0]),/font-weight:400/);
    });
}

// Editing, layers and media
{
    const richDocument = {type: 'doc', content: [{type: 'paragraph', content: [
        {type: 'text', text: 'Texto ', marks: [{type: 'bold'}]},
        {type: 'text', text: 'sublinhado', marks: [{type: 'underline'}, {type: 'highlight', attrs: {color: '#ffff00'}}, {type: 'textColor', attrs: {'data-color': '#E60014'}}]},
        {type: 'hardBreak'}, {type: 'text', text: '<script>alert(1)</script>', marks: [{type: 'link', attrs: {href: 'javascript:alert(1)'}}]},
    ]}]};

    function editor() { const state=createCanvas([],[],500,400,'');state.add('text');Object.assign(state.current.data.dados,{text:'Digite seu texto',font_size:12,color:'#222222'});return state; }

    test('rich text retains partial formatting and escapes unsafe text, links and CSS',()=>{
        const html=renderRichDocument(richDocument);
        assert.match(html,/<strong>Texto <\/strong>/);
        assert.match(html,/<u>sublinhado<\/u>/);
        assert.match(html,/background-color:#ffff00/);
        assert.match(html,/color:#E60014/);
        assert.match(html,/&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
        assert.doesNotMatch(html,/href="javascript:|<script>/);
        assert.equal(plainRichDocument(richDocument),'Texto sublinhado\n<script>alert(1)</script>');
        assert.equal(renderRichDocument({type:'doc',content:[{type:'text',text:'Teste',marks:[{type:'textColor',attrs:{'data-color':'red;position:fixed'}}]}]}),'Teste');
        assert.match(renderRichDocument({type:'doc',content:[{type:'text',text:'Cor Filament',marks:[{type:'textColor',attrs:{'data-color':'red'}}]}]},{red:{color:'oklch(0.577 0.245 27.325)'}}),/style="color:oklch\(0\.577 0\.245 27\.325\)" data-color="red"/);
    });

    test('Filament formatting updates local preview, undo and save without a preview request',async()=>{
        const state=editor(),key=state.current._key;
        state.applyRichText({document:richDocument},key);
        assert.match(state.renderContent(state.current),/<u>sublinhado<\/u>/);
        assert.equal(state.current.data.dados.text,plainRichDocument(richDocument));
        state.undo();assert.equal(state.blocks[0].data.dados.rich_text,undefined);
        state.redo();state.selected=0;
        let payload;
        await state.save({salvar:async blocks=>{payload=blocks;return blocks;}});
        assert.deepEqual(payload[0].data.dados.rich_text,richDocument);
        assert.equal(state.current._key,key);
        assert.equal(state.dirty,false);
    });

    test('late inspector events cannot edit a different selected element or change invalid styles',()=>{
        const state=editor(),first=state.current._key;state.add('text');Object.assign(state.current.data.dados,{font_size:12,color:'#222222'});
        state.applyRichText({document:richDocument},first);
        assert.equal(state.blocks[0].data.dados.rich_text,undefined);
        assert.equal(state.current.data.dados.rich_text,undefined);
        state.applyTextStyle({key:state.current._key,property:'color',value:'red;position:fixed'});
        state.applyTextStyle({key:state.current._key,property:'font_size',value:10000});
        assert.equal(state.current.data.dados.color,'#222222');assert.equal(state.current.data.dados.font_size,12);
        state.applyTextStyle({key:state.current._key,property:'color',value:'#ff0000'});
        state.applyTextStyle({key:state.current._key,property:'font_size',value:19});
        assert.equal(state.current.data.dados.color,'#ff0000');assert.equal(state.current.data.dados.font_size,19);
    });

    test('dragged layers preserve selected identity, saved IDs and undo while rejecting duplicate sort keys',()=>{
        const state=editor();state.current.data.id=10;state.add('image');state.current.data.id=20;state.add('drawing');state.current.data.id=30;
        const keys=state.blocks.map(block=>block._key);state.selected=1;
        state.reorderLayers([keys[1],keys[2],keys[0]]);
        assert.deepEqual(state.blocks.map(block=>block.data.id),[10,30,20]);
        assert.equal(state.current.data.id,20);
        assert.equal(state.current._key,keys[1]);
        state.reorderLayers([keys[0],keys[0],keys[1]]);assert.deepEqual(state.blocks.map(block=>block.data.id),[10,30,20]);
        state.undo();assert.deepEqual(state.blocks.map(block=>block.data.id),[10,20,30]);
    });

    test('replacing an image clears an old crop while stale selection and unregistered assets are ignored',()=>{
        const state=createCanvas([],[{id:7,image:'/media/seven.png'}],500,400,'');state.add('asset');
        state.current.data.dados.image_transform={x:-10,y:0,width:400,height:100,rotation:0};
        state.selectAsset(99,state.current._key);assert.equal(state.current.data.imagem_origem,null);
        state.selectAsset(7,state.current._key);assert.equal(state.current.data.imagem_origem,7);assert.equal(state.current.data.imagem_url,'/media/seven.png');
        assert.equal(state.current.data.dados.image_transform,null);
        state.undo();state.selected=0;assert.equal(state.current.data.dados.image_transform.x,-10);
    });

    test('uploading adds the returned asset without changing an element selected after upload began',async()=>{
        const state=editor();state.add('asset');const first=state.current._key;
        let finish,calls=0;
        const promise=state.uploadImage({uploadAsset:()=>new Promise(resolve=>finish=resolve)});
        await state.save({salvar:async blocks=>{calls++;return blocks;}});
        assert.equal(calls,0);assert.equal(state.imageUploading,true);
        state.add('asset');assert.notEqual(state.current._key,first);
        finish({asset:{id:8,image:'/media/eight.png'},assets:[{id:8,image:'/media/eight.png'}]});await promise;
        assert.equal(state.assets.length,1);assert.equal(state.current.data.imagem_origem,null);assert.equal(state.imageUploading,false);
    });

    test('a Livewire upload response without an asset leaves an actionable error instead of silent success',async()=>{
        const state=editor();state.add('asset');await state.uploadImage({uploadAsset:async()=>undefined});
        assert.match(state.error,/Não foi possível enviar a mídia/);assert.equal(state.imageUploading,false);
    });
}
