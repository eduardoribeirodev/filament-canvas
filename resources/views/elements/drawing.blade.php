<svg class="drawing" :view-box.camel="'0 0 '+data.geometry.width+' '+data.geometry.height" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" aria-hidden="true">
    <g :stroke="data.line.stroke" :stroke-width="data.line.weight" :stroke-dasharray="data.line.dashes" :stroke-linecap="data.line.cap" :fill="/^#[a-f\d]{3}(?:[a-f\d]{3})?$/i.test(data.drawing?.fill || '') ? data.drawing.fill : 'none'" stroke-linejoin="round">
        <polygon x-show="data.drawing?.closed" :points="(data.drawing?.points || []).map(p => p.x*data.geometry.width/100+','+p.y*data.geometry.height/100).join(' ')" />
        <polyline x-show="!data.drawing?.closed" :points="(data.drawing?.points || []).map(p => p.x*data.geometry.width/100+','+p.y*data.geometry.height/100).join(' ')" fill="none" />
    </g>
</svg>
