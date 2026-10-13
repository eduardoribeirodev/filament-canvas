@php
    $size = isset($document) ? [$document->width, $document->height] : ($data['page_size'] ?? [config('filament-canvas.width', 595.28), config('filament-canvas.height', 841.89)]);
    $width = (float) $size[0];
    $height = (float) $size[1];
@endphp
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"></head><body>
@include($template)
<style>
body{margin:0}
@page{size:{{ $width }}pt {{ $height }}pt;margin:0}
@if($preview)
html{width:{{ $width }}px;height:{{ $height }}px;overflow:hidden}
body{width:{{ $width }}pt;height:{{ $height }}pt;zoom:.75;overflow:visible}
@endif
</style>
<script>
window.canvasReady = false;
(async () => {
    await (window.canvasTemplateReady ?? Promise.resolve());
    await document.fonts.ready;
    await Promise.all([...document.images].map(image => image.complete ? Promise.resolve() : new Promise(resolve => {
        image.addEventListener('load', resolve, {once: true});
        image.addEventListener('error', resolve, {once: true});
    })));
    window.canvasReady = true;
})().catch(error => { console.error(error); window.canvasError = String(error); });
</script>
</body></html>
