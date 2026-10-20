<div data-canvas-drawing-controls x-cloak x-show="drawingSession" @pointerdown.stop @keydown.escape.stop.prevent="cancelDrawing()" style="position:absolute;left:12px;top:12px;z-index:100;max-width:calc(100% - 24px);padding:10px;background:white;color:#111;border:1px solid #2563eb;border-radius:6px;font:12px/1.4 sans-serif;box-shadow:0 2px 8px #0002">
    <span>{{ __('filament-canvas::canvas.drawing_help') }}</span>
    <button type="button" style="margin-left:8px;color:#2563eb;text-decoration:underline" @click.stop="finishDrawing()">{{ __('filament-canvas::canvas.finish_drawing') }}</button>
    <button type="button" style="margin-left:8px;color:#dc2626;text-decoration:underline" @click.stop="cancelDrawing()">{{ __('filament-canvas::canvas.cancel_drawing') }}</button>
</div>
