<div x-cloak x-show="current" class="canvas-native-fields space-y-4"
    x-bind:inert="inspectorHydrating" x-bind:aria-busy="inspectorHydrating"
    :class="{'opacity-60':inspectorHydrating}"
    data-canvas-properties
    data-canvas-inspector-key="{{ $this->inspectorKey }}"
    data-canvas-inspector-generation="{{ $this->inspectorGeneration }}"
    wire:key="canvas-properties-{{ $this->inspectorKey ?? 'none' }}-{{ $this->inspectorGeneration }}"
    x-init="$nextTick(() => syncInspector())">
    @if ($this->inspectorType !== null)
        {{ $this->propertiesForm }}
    @endif
    <p x-show="current && isText(current)" class="text-xs text-gray-500 dark:text-gray-400">{{ __('filament-canvas::canvas.inline_hint') }}</p>
</div>
