import test from 'node:test';
import assert from 'node:assert/strict';
import createCanvas from '../resources/js/canvas.js';

const element=(type='text')=>({type,data:{nome:'Exemplo',x_pt:10,y_pt:10,largura_pt:100,altura_pt:50,rotacao_graus:0,visivel:true,dados:{text:'<Teste>',font_size:12}}});

test('geometry honors rotation, shift ratio and centered resize',()=>{
 const state=createCanvas([],[],500,400,'');state.grid=false;
 const data=element().data;
 assert.deepEqual(state.resizeGeometry(data,20,10,'se',{}),{x_pt:10,y_pt:10,largura_pt:120,altura_pt:60});
 assert.deepEqual(state.resizeGeometry(data,20,10,'se',{altKey:true}),{x_pt:-10,y_pt:0,largura_pt:140,altura_pt:70});
 const shift=state.resizeGeometry(data,20,0,'se',{shiftKey:true});assert.equal(shift.largura_pt/shift.altura_pt,2);
 const rotated=state.resizeGeometry({...data,rotacao_graus:90},0,-20,'se',{});assert.equal(rotated.largura_pt,120);
});

test('generic blocks normalize, escape content, preserve undo and redo',()=>{
 const state=createCanvas([],[],150,100,'');state.add('text');assert.equal(state.current.data.largura_pt,150);assert.equal(state.current.data.x_pt,0);
 state.current.data.dados.text='<script>alert(1)</script>';assert.match(state.renderContent(state.current),/&lt;script&gt;/);
 state.undo();assert.equal(state.blocks.length,0);state.redo();assert.equal(state.blocks.length,1);
 state.selected=0;state.duplicate();assert.equal(state.blocks.length,2);assert.notEqual(state.blocks[0]._key,state.blocks[1]._key);
});

test('safe images and table content cannot inject HTML',()=>{
 const state=createCanvas();assert.equal(state.safeImageUrl('javascript:alert(1)'), '');assert.equal(state.safeImageUrl('//untrusted.example/image.png'),'');assert.equal(state.safeImageUrl('https://example.test/photo.png'),'https://example.test/photo.png');
 state.add('data-table');state.current.data.dados.columns=[{label:'<b>',field:'value'}];state.current.data.dados.rows=[{value:'<img onerror=alert(1)>'}];assert.match(state.renderContent(state.current),/&lt;img/);assert.doesNotMatch(state.renderContent(state.current),/<img/);
});

test('extension retains custom methods and getters without model dependencies',()=>{
 const state=createCanvas([],[],500,400,'',{extension(core){return {extra:'AK',get custom(){return this.extra+' '+this.pageWidth},hello(){return core.pageHeight}}}});
 assert.equal(state.custom,'AK 500');assert.equal(state.hello(),400);state.extra='Other';assert.equal(state.custom,'Other 500');
});

test('saving strips browser keys, preserves returned IDs and preview identities',async()=>{
 const state=createCanvas([element()],[],500,400,'');state.prepare();const key=state.blocks[0]._key,signature=state.contentSignature(state.blocks[0]);
 let sent;await state.save({salvar:async blocks=>{sent=blocks;return blocks.map(b=>({...b,data:{...b.data,id:123}}))}});
 assert.equal(sent[0]._key,undefined);assert.equal(state.blocks[0]._key,key);assert.equal(state.blocks[0].data.id,123);assert.equal(state.dirty,false);assert.equal(state.contentSignature(state.blocks[0]),signature);
});

test('text columns and hyperlinks remain safe in the local preview',()=>{
 const state=createCanvas([element()],[],500,400,'');state.prepare();state.selected=0;
 Object.assign(state.current.data.dados,{leading:1.6,columns:2,link_url:'https://example.test/?q="quoted"'});
 assert.match(state.renderContent(state.current),/line-height:1.6;column-count:2/);
 assert.match(state.renderLink(state.current),/q=&quot;quoted&quot;/);
 state.current.data.dados.link_url='javascript:alert(1)';assert.equal(state.renderLink(state.current),'');
});

test('named image elements inherit preview and crop capabilities',()=>{
 const state=createCanvas([], [{id:'logo',image:'/media/logo.png'}], 500, 400, '', {labels:{logo:'Logo'},elementTypes:{logo:{kind:'image',capabilities:{image:true,media:true},media_collections:{image:{reference_field:'imagem_origem',max_files:1}},local_renderer:'image'}}});
 state.add('logo');state.selectAsset('logo',state.current._key);assert.ok(state.imageTypes.includes('logo'));assert.match(state.renderContent(state.current),/\/media\/logo.png/);
 state.flipImage('x');assert.equal(state.current.data.dados.flip_x,true);
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
 state.$wire={createElement:async type=>{calls.push(type);return {type,data:{nome:'Selo',dados:{text:'Padrão do formulário',enabled:false,count:0}}}}};
 await state.add('badge');
 assert.deepEqual(calls,['badge']);assert.equal(state.current.data.dados.text,'Padrão do formulário');assert.equal(state.current.data.dados.enabled,false);assert.equal(state.current.data.dados.count,0);
 assert.equal(state.current.data.conteudo,undefined);assert.equal(state.current.data.configuracoes,undefined);assert.equal(state.current.data.estilo,undefined);
});

test('a replacement native RichEditor receives the selected document after a Livewire morph',()=>{
 const previousAlpine=globalThis.Alpine;
 const state=createCanvas([element()],[],500,400,'');state.prepare();state.selected=0;
 const expected={type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Texto selecionado'}]}]};
 state.current.data.dados.rich_text=expected;
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
