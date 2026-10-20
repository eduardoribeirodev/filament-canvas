<template x-if="isDrawingEditing(b)">
    <div style="position:absolute;inset:0;pointer-events:none" data-canvas-drawing-points>
        <svg x-show="drawingSession?.creating && drawingCursor" :viewBox="'0 0 '+b.data.width_pt+' '+b.data.height_pt" style="position:absolute;inset:0;width:100%;height:100%;overflow:visible" aria-hidden="true">
            <polyline :points="[...(b.data.properties.drawing.points || []),...(drawingCursor ? [drawingCursor] : [])].map(p=>p.x*b.data.width_pt/100+','+p.y*b.data.height_pt/100).join(' ')" fill="none" stroke="#2563eb" stroke-dasharray="4 4" stroke-width="1" />
        </svg>
        <template x-for="(point,pointIndex) in b.data.properties.drawing.points" :key="pointIndex">
            <button type="button" data-canvas-point :aria-label="{{ \Illuminate\Support\Js::from(__('filament-canvas::canvas.points')) }}+' '+(pointIndex+1)"
                :style="{position:'absolute',left:point.x+'%',top:point.y+'%',width:'14px',height:'14px',transform:'translate(-50%,-50%)',border:'2px solid white',borderRadius:'50%',background:pointIndex===0?'#16a34a':'#2563eb',boxShadow:'0 0 0 1px #2563eb',pointerEvents:'auto',cursor:'move',zIndex:60}"
                @pointerdown.stop="drawingPointStart($event,pointIndex)" @dblclick.stop></button>
        </template>
    </div>
</template>
