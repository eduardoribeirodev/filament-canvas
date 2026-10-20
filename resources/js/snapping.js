/** Geometry in document units; tolerance is measured in screen pixels. */
export function elementBounds(data) {
    const width=Number(data.width_pt),height=Number(data.height_pt),angle=Number(data.rotation_deg || 0)*Math.PI/180;
    const cx=Number(data.x_pt)+width/2,cy=Number(data.y_pt)+height/2;
    const halfWidth=(Math.abs(width*Math.cos(angle))+Math.abs(height*Math.sin(angle)))/2;
    const halfHeight=(Math.abs(width*Math.sin(angle))+Math.abs(height*Math.cos(angle)))/2;
    return {x:[cx-halfWidth,cx,cx+halfWidth],y:[cy-halfHeight,cy,cy+halfHeight]};
}
function targets(blocks,key,width,height) {
    const result={x:[0,width/2,width],y:[0,height/2,height]};
    for(const block of blocks) {
        if(block._key===key || block.data.visible===false)continue;
        const bounds=elementBounds(block.data);result.x.push(...bounds.x);result.y.push(...bounds.y);
    }
    return result;
}
function nearest(anchors,lines,tolerance) {
    let best=null;
    for(const position of lines)for(const anchor of anchors) {
        const delta=position-anchor;
        if(Math.abs(delta)<=tolerance && (!best || Math.abs(delta)<Math.abs(best.delta)))best={position,delta};
    }
    return best;
}
export function alignMove(data,blocks,key,width,height,zoom,tolerance=6) {
    const bounds=elementBounds(data),lines=targets(blocks,key,width,height),radius=tolerance/zoom;
    const x=nearest(bounds.x,lines.x,radius),y=nearest(bounds.y,lines.y,radius);
    return {geometry:{x_pt:Number(data.x_pt)+(x?.delta || 0),y_pt:Number(data.y_pt)+(y?.delta || 0)},guides:[...(x?[{axis:'x',position:x.position}]:[]),...(y?[{axis:'y',position:y.position}]:[])]};
}
export function alignResize(data,original,corner,modifiers,blocks,key,width,height,zoom,tolerance=6) {
    // Rotated resize retains its local axes; movement still aligns rotated bounding boxes.
    if(Math.abs(Number(original.rotation_deg || 0)%360)>.00001)return {geometry:data,guides:[]};
    const west=corner.includes('w'),north=corner.includes('n'),lines=targets(blocks,key,width,height),radius=tolerance/zoom;
    const x=nearest([Number(data.x_pt)+(west?0:Number(data.width_pt))],lines.x,radius);
    const y=nearest([Number(data.y_pt)+(north?0:Number(data.height_pt))],lines.y,radius);
    const factor=modifiers.altKey?2:1;
    let w=Number(data.width_pt),h=Number(data.height_pt),matchX=x,matchY=y;
    if(modifiers.shiftKey && x && y) {
        if(Math.abs(x.delta)<=Math.abs(y.delta))matchY=null;else matchX=null;
    }
    if(matchX) {w+=(west?-1:1)*matchX.delta*factor;if(modifiers.shiftKey)h=w*Number(original.height_pt)/Number(original.width_pt);}
    if(matchY) {h+=(north?-1:1)*matchY.delta*factor;if(modifiers.shiftKey)w=h*Number(original.width_pt)/Number(original.height_pt);}
    const geometry={...data,width_pt:w,height_pt:h,
        x_pt:modifiers.altKey?Number(original.x_pt)+(Number(original.width_pt)-w)/2:west?Number(original.x_pt)+Number(original.width_pt)-w:Number(original.x_pt),
        y_pt:modifiers.altKey?Number(original.y_pt)+(Number(original.height_pt)-h)/2:north?Number(original.y_pt)+Number(original.height_pt)-h:Number(original.y_pt)};
    if(w<1 || h<1 || geometry.x_pt<0 || geometry.y_pt<0 || geometry.x_pt+w>width || geometry.y_pt+h>height)return {geometry:data,guides:[]};
    const guides=[];
    if(x && Math.abs(geometry.x_pt+(west?0:w)-x.position)<.00001)guides.push({axis:'x',position:x.position});
    if(y && Math.abs(geometry.y_pt+(north?0:h)-y.position)<.00001)guides.push({axis:'y',position:y.position});
    return {geometry,guides};
}
