/** Interactive point editing; coordinates are percentages of the element's local frame. */
export const drawingTools = {
    drawingSession: null,
    drawingCursor: null,
    isDrawing(block) { return this.elementTypes[block?.type]?.kind === 'drawing'; },
    isDrawingEditing(block) { return Boolean(block && this.drawingSession?.key === block._key); },
    beginDrawing(block, before) {
        Object.assign(block.data, {x_pt:0,y_pt:0,width_pt:this.pageWidth,height_pt:this.pageHeight,rotation_deg:0});
        block.data.properties.drawing = {...block.data.properties.drawing,points:[],closed:false};
        this.drawingSession = {key:block._key,creating:true,...before};
        this.drawingCursor = null;
        this.$nextTick?.(() => this.$refs.stage?.focus({preventScroll:true}));
    },
    editDrawing(event, index) {
        event?.preventDefault?.();event?.stopPropagation?.();
        if (this.saving || !this.isDrawing(this.blocks[index])) return;
        if (this.drawingSession) this.finishDrawing();
        this.selected=index;this.cropIndex=null;this.endGesture();
        this.drawingSession={key:this.current._key,creating:false,snapshot:this.snapshot(),undoDepth:this.undoStack.length,dirty:this.dirty,redo:[...this.redoStack]};
        this.$refs.stage?.focus({preventScroll:true});
    },
    drawingLocalPoint(event, block) {
        const paper=this.$refs.paper.getBoundingClientRect(),d=block.data;
        const x=(event.clientX-paper.left)/this.zoom-Number(d.x_pt)-Number(d.width_pt)/2;
        const y=(event.clientY-paper.top)/this.zoom-Number(d.y_pt)-Number(d.height_pt)/2;
        const angle=-Number(d.rotation_deg || 0)*Math.PI/180;
        return {x:Math.max(0,Math.min(100,((x*Math.cos(angle)+y*Math.sin(angle))/Number(d.width_pt)+.5)*100)),
            y:Math.max(0,Math.min(100,((-x*Math.sin(angle)+y*Math.cos(angle))/Number(d.height_pt)+.5)*100))};
    },
    drawingPointerDown(event) {
        if (!this.drawingSession?.creating || event.button!==0 || event.target?.closest?.('[data-canvas-point],[data-canvas-drawing-controls]')) return;
        event.preventDefault();event.stopImmediatePropagation?.();event.stopPropagation();
        const points=this.current?.data.properties.drawing.points;
        if (!points || points.length>=300) return;
        points.push(this.drawingLocalPoint(event,this.current));
        this.drawingCursor=null;this.schedulePreview();this.$refs.stage?.focus({preventScroll:true});
    },
    drawingPointerMove(event) {
        if (this.drawingSession?.creating && this.current) this.drawingCursor=this.drawingLocalPoint(event,this.current);
    },
    drawingPointStart(event, index) {
        event.preventDefault();event.stopPropagation();
        if (this.saving || !this.current || !this.isDrawingEditing(this.current)) return;
        if (this.drawingSession.creating) { if(index===0)this.finishDrawing(true);return; }
        this.gesture={mode:'drawing-point',point:index,snapshot:this.snapshot(),changed:false,x:event.clientX,y:event.clientY,d:JSON.parse(JSON.stringify(this.current.data))};
        event.currentTarget.setPointerCapture(event.pointerId);
    },
    finishDrawing(closed = null) {
        const session=this.drawingSession;if(!session)return true;
        const block=this.blocks.find(block=>block._key===session.key);
        if(!block){this.drawingSession=null;return true;}
        const drawing=block.data.properties.drawing,shouldClose=closed ?? drawing.closed ?? false;
        if(drawing.points.length < (shouldClose?3:2)) {this.error=this.messages.drawing_min_points;return false;}
        if(session.creating) {
            const points=drawing.points.map(p=>({x:p.x*this.pageWidth/100,y:p.y*this.pageHeight/100}));
            const left=Math.max(0,Math.min(...points.map(p=>p.x))-4),top=Math.max(0,Math.min(...points.map(p=>p.y))-4);
            const width=Math.max(1,Math.min(this.pageWidth,Math.max(...points.map(p=>p.x))+4)-left);
            const height=Math.max(1,Math.min(this.pageHeight,Math.max(...points.map(p=>p.y))+4)-top);
            Object.assign(block.data,{x_pt:left,y_pt:top,width_pt:width,height_pt:height});
            drawing.points=points.map(p=>({x:(p.x-left)/width*100,y:(p.y-top)/height*100}));
        } else if(shouldClose!==drawing.closed) this.checkpoint();
        drawing.closed=shouldClose;this.drawingSession=null;this.drawingCursor=null;this.error='';this.endGesture();
        this.schedulePreview();this.syncInspector();return true;
    },
    cancelDrawing() {
        const session=this.drawingSession;if(!session)return;
        this.drawingSession=null;this.drawingCursor=null;this.endGesture();
        this.blocks=JSON.parse(session.snapshot);this.undoStack.length=session.undoDepth;this.redoStack=session.redo;this.dirty=session.dirty;
        this.selected=null;this.error='';this.prepare();this.schedulePreview();this.syncInspector();
    },
};
