<div wire:ignore wire:key="canvas-inline-editor-{{ $this->inspectorType ?? 'text' }}" data-canvas-inline-editor-host x-ref="inlineTextHost" class="canvas-inline-editor-host"
    x-cloak x-show="textEditing" :style="inlineEditorStyle()"
    @run-rich-editor-commands.window="resumeInlineEditing($event.detail)"
    @pointerdown.stop @dblclick.stop @keydown.stop="textEditorKey($event)"
    @canvas-inline-text-ready.stop="mountTextEditor()"
    @canvas-inline-text-change.stop="inputText($event.detail)">
    {{ $this->inlineTextForm }}
</div>
