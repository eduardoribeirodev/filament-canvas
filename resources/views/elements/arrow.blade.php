<svg class="drawing" :view-box.camel="'0 0 '+data.geometry.width+' '+data.geometry.height" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" aria-hidden="true">
    <g :stroke="data.line.stroke" :stroke-width="data.line.weight" :stroke-dasharray="data.line.dashes" :stroke-linecap="data.line.cap" :fill="/^#[a-f\d]{3}(?:[a-f\d]{3})?$/i.test(data.drawing?.fill || '') ? data.drawing.fill : 'none'" stroke-linejoin="round">
        <polygon :points="'0,'+data.geometry.height*.3+' '+data.geometry.width*.65+','+data.geometry.height*.3+' '+data.geometry.width*.65+',0 '+data.geometry.width+','+data.geometry.height*.5+' '+data.geometry.width*.65+','+data.geometry.height+' '+data.geometry.width*.65+','+data.geometry.height*.7+' 0,'+data.geometry.height*.7" />
    </g>
</svg>
