@php
    $size = isset($document) ? [$document->width, $document->height] : ($data['page_size'] ?? [config('filament-canvas.width', 595.28), config('filament-canvas.height', 841.89)]);
    $width = (float) $size[0];
    $height = (float) $size[1];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"></head><body>
@include($template)
<style>
body{margin:0}
@page{size:{{ $width }}pt {{ $height }}pt;margin:0}
@if($preview)
html{width:{{ $width }}px;height:{{ $height }}px;overflow:hidden}
body{width:{{ $width }}pt;height:{{ $height }}pt;zoom:.75;overflow:visible}
@endif
</style>
<script type="application/json" data-canvas-document-data>{!! json_encode($document->metadata ?? [], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@stack('canvas-display-scripts')
<script>{!! str_ireplace('</script', '<\/script', file_get_contents(dirname((new \ReflectionClass(\EduardoRibeiroDev\FilamentCanvas\CanvasPlugin::class))->getFileName(), 2).'/resources/dist/document.js')) !!}</script>
</body></html>
