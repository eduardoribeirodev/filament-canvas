import test from 'node:test';
import assert from 'node:assert/strict';
import createCanvas from '../resources/js/canvas.js';

const element=(type='text')=>({type,data:{name:'Exemplo',x_pt:10,y_pt:10,width_pt:100,height_pt:50,rotation_deg:0,visible:true,properties:{text:'<Teste>',font_size:12}}});

test('geometry honors rotation, shift ratio and centered resize',()=>{
 const state=createCanvas([],[],500,400,'');state.grid=false;
 const data=element().data;
 assert.deepEqual(state.resizeGeometry(data,20,10,'se',{}),{x_pt:10,y_pt:10,width_pt:120,height_pt:60});
 assert.deepEqual(state.resizeGeometry(data,20,10,'se',{altKey:true}),{x_pt:-10,y_pt:0,width_pt:140,height_pt:70});
 const shift=state.resizeGeometry(data,20,0,'se',{shiftKey:true});assert.equal(shift.width_pt/shift.height_pt,2);
 const rotated=state.resizeGeometry({...data,rotation_deg:90},0,-20,'se',{});assert.equal(rotated.width_pt,120);
});

test('generic blocks normalize, escape content, preserve undo and redo',()=>{
 const state=createCanvas([],[],150,100,'');state.add('text');assert.equal(state.current.data.width_pt,150);assert.equal(state.current.data.x_pt,0);
 state.current.data.properties.text='<script>alert(1)</script>';assert.match(state.viewData(state.current).html,/&lt;script&gt;/);
 state.undo();assert.equal(state.blocks.length,0);state.redo();assert.equal(state.blocks.length,1);
 state.selected=0;state.duplicate();assert.equal(state.blocks.length,2);assert.notEqual(state.blocks[0]._key,state.blocks[1]._key);
});

test('safe images and table content cannot inject HTML',()=>{
 const state=createCanvas();assert.equal(state.safeImageUrl('javascript:alert(1)'), '');assert.equal(state.safeImageUrl('//untrusted.example/image.png'),'');assert.equal(state.safeImageUrl('https://example.test/photo.png'),'https://example.test/photo.png');
 state.add('data-table');state.current.data.properties.columns=[{label:'<b>',field:'value'}];state.current.data.properties.rows=[{value:'<img onerror=alert(1)>'}];assert.equal(state.viewData(state.current).rows[0].value,'<img onerror=alert(1)>');
});

test('saving strips browser keys, preserves returned IDs and preview identities',async()=>{
 const state=createCanvas([element()],[],500,400,'');state.prepare();const key=state.blocks[0]._key,signature=state.contentSignature(state.blocks[0]);
 let sent;await state.save({save:async blocks=>{sent=blocks;return blocks.map(b=>({...b,data:{...b.data,id:123}}))}});
 assert.equal(sent[0]._key,undefined);assert.equal(state.blocks[0]._key,key);assert.equal(state.blocks[0].data.id,123);assert.equal(state.dirty,false);assert.equal(state.contentSignature(state.blocks[0]),signature);
});

test('text columns and hyperlinks remain safe in the local preview',()=>{
 const state=createCanvas([element()],[],500,400,'');state.prepare();state.selected=0;
 Object.assign(state.current.data.properties,{leading:1.6,columns:2,link_url:'https://example.test/?q="quoted"'});
 assert.equal(state.viewData(state.current).text_style.lineHeight,1.6);assert.equal(state.viewData(state.current).text_style.columnCount,2);
 assert.equal(state.viewData(state.current).link_url,'https://example.test/?q="quoted"');
 state.current.data.properties.link_url='javascript:alert(1)';assert.equal(state.viewData(state.current).link_url,'');
});

test('named image elements inherit preview and crop capabilities',()=>{
 const state=createCanvas([], [{id:'logo',image:'/media/logo.png'}], 500, 400, '', {labels:{logo:'Logo'},elementTypes:{logo:{kind:'image',media_collections:{image:{reference_field:'image_id',max_files:1}}}}});
 state.add('logo');state.selectAsset('logo',state.current._key);assert.ok(state.imageTypes.includes('logo'));assert.equal(state.viewData(state.current).image_url,'/media/logo.png');
 state.flipImage('x');assert.equal(state.current.data.properties.flip_x,true);
});

test('destroy cancels scheduled rendering and stops the watcher',()=>{
 const previousRaf=globalThis.requestAnimationFrame, previousCancel=globalThis.cancelAnimationFrame;
 let callback, cancelled, disposed=0, rendered=0;
 globalThis.requestAnimationFrame=(fn)=>{callback=fn;return 42};globalThis.cancelAnimationFrame=id=>cancelled=id;
 try {
   const state=createCanvas();state.disposeWatch=()=>disposed++;state.syncPreview=()=>rendered++;
   state.schedulePreview();state.destroy();callback();state.schedulePreview();
   assert.equal(cancelled,42);assert.equal(disposed,1);assert.equal(rendered,0);assert.equal(state.previewFrame,null);
 } finally {globalThis.requestAnimationFrame=previousRaf;globalThis.cancelAnimationFrame=previousCancel}
});

test('new element values come from the native schema creation RPC',async()=>{
 const state=createCanvas([],[],500,400,'',{labels:{badge:'Selo'}});
 const calls=[];
 state.$wire={createElement:async type=>{calls.push(type);return {type,data:{name:'Selo',properties:{text:'Padrão do formulário',enabled:false,count:0}}}}};
 await state.add('badge');
 assert.deepEqual(calls,['badge']);assert.equal(state.current.data.properties.text,'Padrão do formulário');assert.equal(state.current.data.properties.enabled,false);assert.equal(state.current.data.properties.count,0);
 assert.equal(state.current.data.content,undefined);assert.equal(state.current.data.settings,undefined);assert.equal(state.current.data.style,undefined);
});

test('a replacement native RichEditor receives the selected document after a Livewire morph',()=>{
 const previousAlpine=globalThis.Alpine;
 const state=createCanvas([element()],[],500,400,'');state.prepare();state.selected=0;
 const expected={type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Text selecionado'}]}]};
 state.current.data.properties.rich_text=expected;
 const makeNative=()=>{
  const node={dataset:{}};let document={type:'doc',content:[]},updates=0;
  const component={getEditor:()=>({getJSON:()=>document,commands:{setContent:value=>{document=structuredClone(value);updates++;}}})};
  return {node,component,get document(){return document},get updates(){return updates}};
 };
 let native=makeNative();state.$el={querySelector:()=>native.node};state.$nextTick=callback=>callback();
 globalThis.Alpine={$data:()=>native.component};
 try {
  state.syncRichEditor();assert.deepEqual(native.document,expected);assert.equal(native.updates,1);
  state.syncRichEditor();assert.equal(native.updates,1);
  native=makeNative();state.syncRichEditor();assert.deepEqual(native.document,expected);assert.equal(native.updates,1);
  assert.equal(native.node.dataset.canvasSyncedKey,String(state.current._key));
 } finally {globalThis.Alpine=previousAlpine}
});


test('deselecting an element accepts the empty inspector response without a loading error',async()=>{
 const state=createCanvas([element()],[],500,400,'');state.prepare();state.selected=null;
 state.$wire={selectInspector:async()=>({key:null,generation:1,state:[],fields:[],normalized:[]})};
 await state.hydrateInspector();
 assert.equal(state.error,'');assert.equal(state.inspectorHydrating,false);assert.equal(state.inspectorSelection,null);
 assert.equal(state.dirty,false);
 state.error='Unable to load element properties.';
 state.selected=0;
 state.$wire.selectInspector=async()=>({state:{},fields:[],normalized:state.current.data.properties});
 await state.hydrateInspector();assert.equal(state.error,'','a successful retry clears the previous loading error');
});

test('pending inline editing keeps the normalized document formatting until the native editor is ready',()=>{
 const state=createCanvas([element()],[],500,400,'');state.prepare();state.selected=0;
 const block=state.current;
 state.textEditing={key:block._key,html:'Old unformatted text',document:null,element:null};
 block.data.properties.rich_text={type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Formatted text',marks:[{type:'bold'}]}]}]};
 assert.match(state.textHtml(block),/<strong>Formatted text<\/strong>/);
 assert.equal(state.isTextEditorReady(block),false);
 state.textEditing.element={isConnected:true};state.inspectorHydrating=true;
 assert.equal(state.isTextEditorReady(block),false);
 state.inspectorHydrating=false;assert.equal(state.isTextEditorReady(block),true);
 state.textEditing.element.isConnected=false;assert.equal(state.isTextEditorReady(block),false);
});

test('frontend failures use localized messages supplied by the editor',async()=>{
 const messages={save_error:'Impossible d’enregistrer.',create_error:'Não foi possível criar o elemento.'};
 const state=createCanvas([],[],500,400,'',{messages});
 await state.save({save:async()=>{throw new Error('failure');}});
 assert.equal(state.error,messages.save_error);
 state.$wire={createElement:async()=>{throw new Error('failure');}};
 await state.add('text');
 assert.equal(state.error,messages.create_error);
 assert.equal(state.adding,false);
});


test('document metadata is available to views without becoming persisted element state',()=>{
 const records=[{id:1,name:'Record'}];
 const state=createCanvas([{type:'custom',data:{name:'Custom',x_pt:0,y_pt:0,width_pt:100,height_pt:50,properties:{caption:'Element caption'}}}],[],500,400,'',{data:{records,caption:'Document caption'}});
 state.prepare();
 const values=state.viewData(state.blocks[0]);
 assert.equal(values.caption,'Element caption');
 assert.deepEqual(values.records,records);
 assert.equal(Object.hasOwn(state.serializeBlock(state.blocks[0]).data.properties,'records'),false);
});

test('custom text and image views can expose their displayed content to the native editing tools',()=>{
 const state=createCanvas();
 const block={_key:'custom',type:'custom',data:{properties:{},image_url:'/old.png'}};
 state.previewNodes.set('custom',{querySelector:selector=>selector==='[data-canvas-text]'?{textContent:'Displayed text'}:{getAttribute:()=>'/displayed.png'}});
 assert.equal(state.text(block),'Displayed text');
 assert.equal(state.image(block),'/displayed.png');
 state.previewNodes.set('custom',{querySelector:()=>({getAttribute:()=>null})});
 assert.equal(state.image(block),'');
});
