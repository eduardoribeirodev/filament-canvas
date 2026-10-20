import { default as assert } from 'node:assert/strict';
import { default as test } from 'node:test';
import { default as createCanvas, renderRichDocument, plainRichDocument } from '../resources/dist/canvas.js';

// canvas-package.test.mjs
{
    function editor(options = {}) {
        return createCanvas([], [], 841.89, 595.28, '', options);
    }

    test('generic tables render their own data and escape headings and cell content', () => {
        const canvas = editor();
        canvas.add('data-table');
        canvas.current.data.properties.columns = [{label: '<script>heading</script>', field: 'value'}];
        canvas.current.data.properties.rows = [{value: '<img src=x onerror=alert(1)>'}];

        const data = canvas.viewData(canvas.current);
        assert.equal(data.columns[0].label,'<script>heading</script>');
        assert.equal(data.rows[0].value,'<img src=x onerror=alert(1)>');
        assert.equal(canvas.products, undefined);
    });

    test('native custom element defaults survive duplication and undo', async () => {
        const canvas = editor({labels: {badge: 'Selo'}, elementTypes: {badge: {kind:'element'}}});
        canvas.$wire={createElement:async type=>({type,data:{properties:{text:'Especial',variant:'red'}}})};
        await canvas.add('badge');
        assert.equal(canvas.viewData(canvas.current).text, 'Especial');
        canvas.duplicate();
        canvas.current.data.properties.text = 'Cópia';
        assert.equal(canvas.blocks[0].data.properties.text, 'Especial');
        assert.equal(canvas.current.data.properties.variant, 'red');
        canvas.undo();
        assert.equal(canvas.blocks.length, 1);
        canvas.selected = 0;
        assert.equal(canvas.current.data.properties.text, 'Especial');
        assert.equal(canvas.current.data.properties.variant, 'red');
    });

    test('custom image types use the asset library and crop inside the existing frame', () => {
        const canvas = createCanvas([], [{id: 7, image: '/assets/photo.png'}], 841.89, 595.28, '', {
            labels: {portrait: 'Retrato'},
            elementTypes: {portrait:{kind:'image',media_collections:{image:{reference_field:'image_id',max_files:1}}}},
        });
        canvas.add('portrait');
        const block = canvas.current;
        block.data.image_id = 7;
        assert.equal(canvas.viewData(block).image_url,'/assets/photo.png');
        canvas.editCrop({preventDefault() {}, stopPropagation() {}, currentTarget: {querySelector: () => ({naturalWidth: 400, naturalHeight: 100})}}, 0);
        assert.equal(canvas.cropIndex, 0);
        assert.equal(block.data.width_pt, 200);
        assert.equal(block.data.height_pt, 100);
        assert.equal(block.data.properties.image_transform.height, 50);
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
        canvas.current.data.properties.text = 'Salvo';
        const key = canvas.current._key;
        let sent;

        await canvas.save({save: async (blocks) => {
            sent = blocks;
            return blocks.map(block => ({...block, data: {...block.data, id: 31}}));
        }});

        assert.equal(sent[0]._key, undefined);
        assert.equal(sent[0].data.properties.text, 'Salvo');
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

// Custom class metadata shares native behavior without a separate renderer.
{
    test('a named text subtype participates in formatting and save with the native text behavior',async()=>{
        const state=createCanvas([{type:'notice',data:{properties:{text:'Notice'}}}],[],500,400,'',{elementTypes:{notice:{kind:'text'}}});
        state.prepare();state.selected=0;
        const document={type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Changed',marks:[{type:'bold'}]}]}]};
        state.applyRichText({document},state.current._key);
        assert.equal(state.isText(state.current),true);
        assert.match(state.viewData(state.current).html,/<strong>Changed<\/strong>/);
        let saved;
        await state.save({save:async blocks=>{saved=blocks;return blocks;}});
        assert.deepEqual(saved[0].data.properties.rich_text,document);
        assert.equal(state.dirty,false);
    });
}

// canvas-native-properties.test.mjs
{
    const block = (name, caption) => ({type:'card',data:{name:name,x_pt:10,y_pt:10,width_pt:100,height_pt:50,rotation_deg:0,visible:true,properties:{caption,preferences:{color:'#111111',slots:[[1,3],[2]]},enabled:false,count:0}}});

    function editor() {
        const state=createCanvas([block('Primeiro','A'),block('Segundo','B')],[],500,400,'',{labels:{card:'Cartão'},elementTypes:{card:{kind:'element'}}});
        state.prepare();state.selected=0;state.inspectorGeneration=1;state.inspectorSelection=String(state.current._key);
        state.inspectorFields=[{path:'caption'},{path:'preferences.color'},{path:'enabled'},{path:'count',numeric:true},{path:'items',list:true,simple:'value'}];
        state.$el={querySelector:selector=>selector==='[data-canvas-properties]'?{dataset:{canvasInspectorKey:String(state.current?._key??''),canvasInspectorGeneration:String(state.inspectorGeneration)}}:null};
        state.$nextTick=callback=>callback();
        return state;
    }


    test('native properties project immediately, preserve hidden nested data and participate in undo',()=>{
        const state=editor();state.$wire={updateInspector:async()=>({stale:true})};
        state.queueInspectorChanges({caption:'Editado',preferences:{color:'#ff0000'},enabled:false,count:0,items:{first:{value:'A'},second:{value:'B'}}});
        assert.equal(state.current.data.properties.caption,'Editado');
        assert.deepEqual(state.current.data.properties.preferences,{color:'#ff0000',slots:[[1,3],[2]]});
        assert.deepEqual(state.current.data.properties.items,['A','B']);
        assert.equal(state.current.data.properties.enabled,false);assert.equal(state.current.data.properties.count,0);
        state.selected=1;state.inspectorPending=null;
        assert.equal(state.blocks[0].data.properties.caption,'Editado','switching selection before the debounce must retain the typed property');
        assert.equal(state.current.data.properties.caption,'B');
        state.undo();assert.equal(state.blocks[0].data.properties.caption,'A');
        state.redo();assert.equal(state.blocks[0].data.properties.caption,'Editado');
    });

    test('a response for an old selection can normalize only its own unchanged block and shares the original undo step',()=>{
        const state=editor();state.$wire={updateInspector:async()=>({stale:true})};
        state.queueInspectorChanges({caption:'editado',preferences:{color:'#111111'},enabled:false,count:0});
        const source=state.serializeBlock(state.current),key=String(state.current._key),revision=state.inspectorRequestRevision;
        state.selected=1;state.inspectorGeneration=2;
        state.applyInspectorPatch({key,generation:1,revision,patch:{caption:'EDITADO'},html:'<b>EDITADO</b>'},source);
        assert.equal(state.blocks[0].data.properties.caption,'EDITADO');assert.equal(state.current.data.properties.caption,'B');
        assert.equal(state.undoStack.length,1);
        state.undo();assert.equal(state.blocks[0].data.properties.caption,'A');
        state.applyInspectorPatch({key,generation:1,revision,patch:{caption:'Tarde'},html:'<b>Tarde</b>'},source);
        assert.equal(state.blocks[0].data.properties.caption,'A','an asynchronous response must not reverse an undo');
    });

    test('named media collections bind their own reference fields and enforce declared item counts',()=>{
        const state=createCanvas([],[{id:1,image:'/one.png'},{id:2,image:'/two.png'},{id:3,image:'/three.png'}],500,400,'',{labels:{card:'Cartão'},elementTypes:{card:{media_collections:{cover:{reference_field:'cover_asset',max_files:1},gallery:{reference_field:'gallery_assets',max_files:2}}}}});
        state.add('card');const key=state.current._key;
        state.selectAsset(1,key,'cover');state.selectAsset([2,3],key,'gallery');
        assert.equal(state.current.data.cover_asset,1);assert.deepEqual(state.current.data.gallery_assets,[2,3]);
        assert.equal(state.current.data.image_id,null);
        state.selectAsset([1,2,3],key,'gallery');assert.deepEqual(state.current.data.gallery_assets,[2,3]);
        assert.deepEqual(state.mediaImages(state.current,'gallery').map(asset=>asset.id),[2,3]);
        assert.equal(Object.hasOwn(state.current.data.properties,'image_transform'),false);
        state.current.data.properties.image_transform={x:1,y:1,width:100,height:100,rotation:0};
        state.selectAsset(2,key,'cover');
        assert.equal(state.current.data.properties.image_transform,null,'selecting new media clears an existing crop');
    });

    test('native RichEditor hydration defaults do not mark an unchanged document as edited',()=>{
        const saved={type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Text',marks:[{type:'highlight'}]}]}]};
        const native={type:'doc',content:[{type:'paragraph',attrs:{textAlign:'start'},content:[{type:'text',marks:[{type:'highlight',attrs:{color:null}}],text:'Text'}]}]};
        const state=editor();state.current.type='text';state.current.data.properties={rich_text:saved,text:'Text'};
        state.inspectorFields=[{path:'rich_text',rich:true}];state.$wire={updateInspector:async()=>({stale:true})};
        state.inspectorValueSignature=state.inspectorStateSignature({rich_text:saved});
        state.queueInspectorChanges({rich_text:native});
        state.applyRichText({document:native});
        assert.equal(state.dirty,false);assert.equal(state.undoStack.length,0);assert.equal(state.inspectorPending,null);
        const changed=structuredClone(native);changed.content[0].content[0].marks.push({type:'bold'});
        state.queueInspectorChanges({rich_text:changed});
        assert.equal(state.dirty,true);assert.equal(state.current.data.properties.rich_text.content[0].content[0].marks.at(-1).type,'bold');
    });

    test('numeric native fields keep their schema type before a validation request or selection change',()=>{
        const state=editor();state.$wire={updateInspector:async()=>({stale:true})};
        state.queueInspectorChanges({caption:'A',preferences:{color:'#111111'},enabled:false,count:'11'});
        assert.equal(state.current.data.properties.count,11);
        state.selected=1;assert.equal(state.blocks[0].data.properties.count,11);
    });

    test('title formatting is taken only from its native rich document',()=>{
        const state=createCanvas([{type:'title',data:{properties:{rich_text:{type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Title'}]}]}}}}]);state.prepare();
        assert.equal(state.viewData(state.blocks[0]).text_style.fontWeight,400);
        assert.doesNotMatch(state.textHtml(state.blocks[0]),/<strong>/);
        state.blocks[0].data.properties.rich_text.content[0].content[0].marks=[{type:'bold'}];
        assert.match(state.textHtml(state.blocks[0]),/<strong>Title<\/strong>/);
    });
}

// Editing, layers and media
{
    const richDocument = {type: 'doc', content: [{type: 'paragraph', content: [
        {type: 'text', text: 'Text ', marks: [{type: 'bold'}]},
        {type: 'text', text: 'sublinhado', marks: [{type: 'underline'}, {type: 'highlight', attrs: {color: '#ffff00'}}, {type: 'textColor', attrs: {'data-color': '#E60014'}}]},
        {type: 'hardBreak'}, {type: 'text', text: '<script>alert(1)</script>', marks: [{type: 'link', attrs: {href: 'javascript:alert(1)'}}]},
    ]}]};

    function editor() { const state=createCanvas([],[],500,400,'');state.add('text');Object.assign(state.current.data.properties,{text:'Enter your text',font_size:12,color:'#222222'});return state; }

    test('rich text retains partial formatting and escapes unsafe text, links and CSS',()=>{
        const html=renderRichDocument(richDocument);
        assert.match(html,/<strong>Text <\/strong>/);
        assert.match(html,/<u>sublinhado<\/u>/);
        assert.match(html,/background-color:#ffff00/);
        assert.match(html,/color:#E60014/);
        assert.match(html,/&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
        assert.doesNotMatch(html,/href="javascript:|<script>/);
        assert.equal(plainRichDocument(richDocument),'Text sublinhado\n<script>alert(1)</script>');
        assert.equal(renderRichDocument({type:'doc',content:[{type:'text',text:'Teste',marks:[{type:'textColor',attrs:{'data-color':'red;position:fixed'}}]}]}),'Teste');
        assert.match(renderRichDocument({type:'doc',content:[{type:'text',text:'Color Filament',marks:[{type:'textColor',attrs:{'data-color':'red'}}]}]},{red:{color:'oklch(0.577 0.245 27.325)'}}),/style="color:oklch\(0\.577 0\.245 27\.325\)" data-color="red"/);
    });

    test('native rich text changes replace cached HTML and plain text before a server response',()=>{
        const state=editor();
        state.current.data.properties={text:'Old',html:'<p>Old</p>',rich_text:{type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Old'}]}]}};
        state.inspectorFields=[{path:'rich_text',rich:true}];
        state.projectInspectorValues({rich_text:richDocument});
        assert.equal(state.current.data.properties.text,plainRichDocument(richDocument));
        assert.equal(state.current.data.properties.html,renderRichDocument(richDocument));
        assert.match(state.textHtml(state.current),/<u>sublinhado<\/u>/);
        state.current.data.properties.html='<p>Stale HTML</p>';
        assert.match(state.textHtml(state.current),/<u>sublinhado<\/u>/);
    });

    test('Filament formatting updates local preview, undo and save without a preview request',async()=>{
        const state=editor(),key=state.current._key;
        state.applyRichText({document:richDocument},key);
        assert.match(state.viewData(state.current).html,/<u>sublinhado<\/u>/);
        assert.equal(state.current.data.properties.text,plainRichDocument(richDocument));
        state.undo();assert.equal(state.blocks[0].data.properties.rich_text,undefined);
        state.redo();state.selected=0;
        let payload;
        await state.save({save:async blocks=>{payload=blocks;return blocks;}});
        assert.deepEqual(payload[0].data.properties.rich_text,richDocument);
        assert.equal(state.current._key,key);
        assert.equal(state.dirty,false);
    });

    test('late rich text events cannot edit a different selected element',()=>{
        const state=editor(),first=state.current._key;state.add('text');Object.assign(state.current.data.properties,{font_size:12,color:'#222222'});
        state.applyRichText({document:richDocument},first);
        assert.equal(state.blocks[0].data.properties.rich_text,undefined);
        assert.equal(state.current.data.properties.rich_text,undefined);
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
        state.current.data.properties.image_transform={x:-10,y:0,width:400,height:100,rotation:0};
        state.selectAsset(99,state.current._key);assert.equal(state.current.data.image_id,null);
        state.selectAsset(7,state.current._key);assert.equal(state.current.data.image_id,7);assert.equal(state.current.data.image_url,'/media/seven.png');
        assert.equal(state.current.data.properties.image_transform,null);
        state.undo();state.selected=0;assert.equal(state.current.data.properties.image_transform.x,-10);
    });

    test('uploading adds the returned asset without changing an element selected after upload began',async()=>{
        const state=editor();state.add('asset');const first=state.current._key;
        let finish,calls=0;
        const promise=state.uploadImage({uploadAsset:()=>new Promise(resolve=>finish=resolve)});
        await state.save({save:async blocks=>{calls++;return blocks;}});
        assert.equal(calls,0);assert.equal(state.imageUploading,true);
        state.add('asset');assert.notEqual(state.current._key,first);
        finish({asset:{id:8,image:'/media/eight.png'},assets:[{id:8,image:'/media/eight.png'}]});await promise;
        assert.equal(state.assets.length,1);assert.equal(state.current.data.image_id,null);assert.equal(state.imageUploading,false);
    });

    test('a Livewire upload response without an asset leaves an actionable error instead of silent success',async()=>{
        const state=editor();state.add('asset');await state.uploadImage({uploadAsset:async()=>undefined});
        assert.match(state.error,/Unable to upload media/);assert.equal(state.imageUploading,false);
    });
}
