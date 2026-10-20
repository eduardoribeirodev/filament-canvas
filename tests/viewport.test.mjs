import { default as test } from 'node:test';
import { default as assert } from 'node:assert/strict';
import { default as createCanvas } from '../resources/dist/canvas.js';
import { alignMove, alignResize, elementBounds } from '../resources/js/snapping.js';

// Viewport anchoring
{
    function editor(options = {}) {
        const state=createCanvas([],[],841.8898,595.2756,'',options);
        const stage={clientWidth:568,clientHeight:506,clientLeft:1,clientTop:1,getBoundingClientRect:()=>({left:440,top:260})};
        for(const [key,size] of [['scrollLeft','width'],['scrollTop','height']]) {
            let offset=0;
            Object.defineProperty(stage,key,{get:()=>offset,set:value=>{offset=Math.max(0,Math.min(Math.round(value),Math.round(state.viewport[size])-stage[size==='width'?'clientWidth':'clientHeight']));}});
        }
        const paper={style:{},getBoundingClientRect:()=>({left:441+Math.floor(state.viewport.x*64)/64-stage.scrollLeft,top:261+Math.floor(state.viewport.y*64)/64-stage.scrollTop,width:Math.floor(state.pageWidth*64)/64*state.zoom})};
        state.$refs={stage,paper,viewport:{style:{}}};
        state.$nextTick=()=>{throw new Error('Viewport must be ready before the next frame.');};
        state.fit();
        return state;
    }
    const point=(state,x,y)=>[(x-441-state.viewport.x+state.$refs.stage.scrollLeft)/state.zoom,(y-261-state.viewport.y+state.$refs.stage.scrollTop)/state.zoom];
    const close=(actual,expected)=>actual.forEach((value,index)=>assert.ok(Math.abs(value-expected[index])<1e-8));

    test('rapid zoom preserves a document point across fit, rounded scrolling, both limits and pan',()=>{
        const state=editor(),x=612,y=401,anchor=point(state,x,y),document=state.snapshot();
        for(let i=0;i<100;i++) {
            state.zoomAt(state.zoom*Math.exp(i<40?.025:-.025),x,y);
            close(point(state,x,y),anchor);
        }
        for(const zoom of [3,.25,3,.25]) {state.zoomAt(zoom,x,y);close(point(state,x,y),anchor);}
        state.$refs.stage.scrollLeft+=20;state.$refs.stage.scrollTop+=13;
        const afterPan=point(state,x,y);
        state.zoomAt(2,x,y);close(point(state,x,y),afterPan);
        assert.equal(state.snapshot(),document);assert.equal(state.dirty,false);assert.equal(state.undoStack.length,0);
        state.fit();assert.equal(state.$refs.stage.scrollLeft,0);assert.equal(state.$refs.stage.scrollTop,0);
        assert.ok(state.viewport.x+state.pageWidth*state.zoom<=state.$refs.stage.clientWidth);
        assert.ok(state.viewport.y+state.pageHeight*state.zoom<=state.$refs.stage.clientHeight);
    });

    test('the zoom slider keeps the stage center anchored and rejects invalid input',()=>{
        const state=editor(),x=441+568/2,y=261+506/2,anchor=point(state,x,y);
        for(const zoom of [.8,1.2,2,.5,.25,3]) {state.setZoom(String(zoom));close(point(state,x,y),anchor);}
        state.setZoom('invalid');assert.equal(state.zoom,3);
        state.gesture={};state.setZoom(1);assert.equal(state.zoom,3);
        state.gesture=null;state.saving=true;state.setZoom(1);assert.equal(state.zoom,3);
    });

    test('configured zoom limits apply to fit, slider input and wheel/pinch without changing the document',()=>{
        const state=editor({minZoom:.1,maxZoom:5}),snapshot=state.snapshot();
        state.setZoom(10);assert.equal(state.zoom,5);
        state.setZoom(.01);assert.equal(state.zoom,.1);
        state.wheel({ctrlKey:true,deltaY:-10000,deltaMode:0,clientX:612,clientY:401,preventDefault(){},stopPropagation(){}});assert.equal(state.zoom,5);
        state.wheel({ctrlKey:true,deltaY:10000,deltaMode:0,clientX:612,clientY:401,preventDefault(){},stopPropagation(){}});assert.equal(state.zoom,.1);
        const pinch={clientX:612,clientY:401,preventDefault(){},stopPropagation(){}};
        state.pinchStart(pinch);state.pinchChange({...pinch,scale:1000});assert.equal(state.zoom,5);
        state.pinchChange({...pinch,scale:.001});assert.equal(state.zoom,.1);state.pinchEnd(pinch);
        const limited=editor({minZoom:.7,maxZoom:.8});assert.equal(limited.zoom,.7);
        limited.setZoom(1);assert.equal(limited.zoom,.8);limited.fit();assert.equal(limited.zoom,.7);
        assert.equal(state.snapshot(),snapshot);assert.equal(state.dirty,false);
        for(const options of [{minZoom:0},{minZoom:-1},{maxZoom:Infinity},{minZoom:2,maxZoom:1},{minZoom:1,maxZoom:1}])assert.throws(()=>editor(options),RangeError);
    });

    test('pixel snapping governs move and resize independently of zoom, and can be disabled',()=>{
        const state=editor();state.add('text');
        for(const zoom of [.5,2]) {
            state.zoom=zoom;state.snapPixels=10;
            Object.assign(state.current.data,{x_pt:80,y_pt:120,width_pt:200,height_pt:100});
            const event={button:0,clientX:0,clientY:0,pointerId:1,preventDefault(){},currentTarget:{setPointerCapture(){}}};
            state.start(event,0,'move');state.move({...event,clientX:7*zoom,clientY:16*zoom});state.gesture=null;
            assert.equal(state.current.data.x_pt,90);assert.equal(state.current.data.y_pt,140);
            const resized=state.resizeGeometry(state.current.data,7,16);
            assert.equal(resized.width_pt,210);assert.equal(resized.height_pt,120);
        }
        for(const step of [1,2,5,10,20]) {state.snapPixels=step;assert.equal(state.snap(87),Math.round(87/step)*step);}
        state.snapPixels=0;assert.equal(state.snap(87.3),87.3);
        state.snapPixels=5;state.grid=false;assert.equal(state.snap(87.3),87.3);
    });
}

// Alignment and snapping
{
    const box=(x,y,w=100,h=50,rotation=0)=>({x_pt:x,y_pt:y,width_pt:w,height_pt:h,rotation_deg:rotation});

    test('magnetic movement aligns page edges and center without changing size',()=>{
        let result=alignMove(box(349,100),[],1,800,600,1);
        assert.equal(result.geometry.x_pt,350);assert.deepEqual(result.guides,[{axis:'x',position:400}]);
        result=alignMove(box(3,2),[],1,800,600,1);
        assert.deepEqual(result.geometry,{x_pt:0,y_pt:0});assert.equal(result.guides.length,2);
    });
    test('other element anchors participate while hidden elements and the selection are ignored',()=>{
        const blocks=[{_key:1,data:box(198,50)},{_key:2,data:box(200,300)},{_key:3,data:{...box(199,500),visible:false}}];
        assert.equal(alignMove(box(198,50),blocks,1,800,700,1).geometry.x_pt,200);
        assert.equal(alignMove(box(198,50),blocks.filter(block=>block._key!==2),1,800,700,1).guides.length,0);
    });
    test('magnetic tolerance uses screen pixels and rotated movement uses visual bounds',()=>{
        assert.equal(alignMove(box(7,70),[],1,800,600,.5).geometry.x_pt,0);
        assert.equal(alignMove(box(7,70),[],1,800,600,2).guides.length,0);
        const result=alignMove(box(120,200,100,50,90),[],1,344,800,1);
        assert.equal(elementBounds({...box(120,200,100,50,90),...result.geometry}).x[1],172);
    });
    test('resizing preserves the opposite corner, Shift ratio and Alt center',()=>{
        const original=box(100,100,120,60),other=[{_key:2,data:box(280,400)}];
        let result=alignResize(box(100,100,177,60),original,'se',{},other,1,1000,800,1);
        assert.equal(result.geometry.width_pt,180);assert.equal(result.geometry.x_pt,100);
        result=alignResize(box(100,100,177,88.5),original,'se',{shiftKey:true},other,1,1000,800,1);
        assert.equal(result.geometry.width_pt/result.geometry.height_pt,2);
        result=alignResize(box(75,100,170,60),original,'se',{altKey:true},[{_key:2,data:box(250,400)}],1,1000,800,1);
        assert.equal(result.geometry.x_pt+result.geometry.width_pt/2,160);
        assert.equal(result.geometry.x_pt+result.geometry.width_pt,250);
    });
    test('Ctrl bypasses alignment and releasing or canceling clears guides without saving preferences',()=>{
        const canvas=createCanvas([{type:'text',data:{...box(30,70),properties:{text:'A'}}}],[],800,600);
        canvas.prepare();canvas.snapPixels=0;canvas.selected=0;canvas.zoom=1;
        canvas.gesture={mode:'move',x:0,y:0,d:{...canvas.current.data}};
        canvas.move({clientX:-27,clientY:0,ctrlKey:true});assert.equal(canvas.current.data.x_pt,3);assert.deepEqual(canvas.snapGuides,[]);
        canvas.move({clientX:-27,clientY:0});assert.equal(canvas.current.data.x_pt,0);assert.equal(canvas.snapGuides.length,1);
        canvas.endGesture();assert.equal(canvas.gesture,null);assert.deepEqual(canvas.snapGuides,[]);
        assert.equal(JSON.parse(canvas.snapshot())[0].data.alignmentSnap,undefined);
    });
}
