import test from 'node:test';
import assert from 'node:assert/strict';
import createCanvas from '../resources/dist/canvas.js';

function editor() {
    const state=createCanvas([],[],500,400,'');
    state.$refs={paper:{getBoundingClientRect:()=>({left:100,top:200})},stage:{focus(){}}};
    state.$nextTick=callback=>callback();state.zoom=2;
    return state;
}
const click=(x,y)=>({button:0,clientX:100+x*2,clientY:200+y*2,preventDefault(){},stopPropagation(){},target:{closest(){return null;}}});
const close=(a,b)=>assert.ok(Math.abs(a-b)<1e-8,`${a} != ${b}`);

test('clicks create a closed drawing in one undo step, with local coordinates that survive save',async()=>{
    const state=editor();state.add('drawing');assert.equal(state.drawingSession.creating,true);
    for(const [x,y] of [[50,60],[200,60],[120,160]])state.drawingPointerDown(click(x,y));
    assert.equal(state.finishDrawing(true),true);assert.equal(state.drawingSession,null);
    const data=state.current.data;assert.equal(data.properties.drawing.closed,true);
    close(data.x_pt+data.properties.drawing.points[0].x*data.width_pt/100,50);
    close(data.y_pt+data.properties.drawing.points[2].y*data.height_pt/100,160);
    assert.equal(state.undoStack.length,1);
    let saved;await state.save({save:async blocks=>(saved=blocks)});
    assert.equal(saved[0].type,'drawing');assert.equal(saved[0].data.properties.drawing.points.length,3);
    assert.equal(saved[0]._key,undefined);
});

test('insufficient points stay editable and Escape cancels without changing the document or undo history',()=>{
    const state=editor();state.redoStack=['future'];const original=state.snapshot();state.add('drawing');
    state.drawingPointerDown(click(40,50));assert.equal(state.finishDrawing(),false);
    state.key({key:'Escape',preventDefault(){},stopPropagation(){}});
    assert.equal(state.snapshot(),original);assert.equal(state.dirty,false);assert.equal(state.undoStack.length,0);assert.deepEqual(state.redoStack,['future']);
});

test('point dragging respects zoom and rotation and can be cancelled or undone',()=>{
    const state=editor();state.add('drawing');state.drawingPointerDown(click(50,60));state.drawingPointerDown(click(150,160));state.finishDrawing();
    state.current.data.rotation_deg=90;const original=state.snapshot(),key=state.current._key;
    state.editDrawing(null,state.selected);
    state.drawingPointStart({...click(100,100),pointerId:1,currentTarget:{setPointerCapture(){}}},0);
    const point={...state.current.data.properties.drawing.points[0]};
    state.move(click(110,100));
    close(state.current.data.properties.drawing.points[0].x,point.x);
    close(state.current.data.properties.drawing.points[0].y,point.y+10/state.current.data.height_pt*100);
    state.cancelDrawing();assert.equal(state.snapshot(),original);
    state.selected=state.blocks.findIndex(block=>block._key===key);state.editDrawing(null,state.selected);
    state.drawingPointStart({...click(100,100),pointerId:1,currentTarget:{setPointerCapture(){}}},0);state.move(click(110,100));state.endGesture();state.finishDrawing();state.undo();assert.equal(state.snapshot(),original);
});

test('a queued preview update ignores a detached iframe during reload',()=>{
    const state=editor();state.previewReady=true;state.previewAnchor={isConnected:true};assert.doesNotThrow(()=>state.syncPreview());
});
