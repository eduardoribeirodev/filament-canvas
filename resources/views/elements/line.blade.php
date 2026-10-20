<svg class="drawing" :view-box.camel="'0 0 '+data.geometry.width+' '+data.geometry.height" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" aria-hidden="true">
    <line :x1="data.line.x1" :y1="data.line.y1" :x2="data.line.x2" :y2="data.line.y2" :stroke="data.line.stroke" :stroke-width="data.line.weight" :stroke-dasharray="data.line.dashes" :stroke-linecap="data.line.cap" fill="none" />
</svg>
