@props(['placeholder' => null])

@php
    // Retain the selected block while Alpine removes this field on a selection change.
    // x-modelable otherwise evaluates the new block before x-if destroys its old field.
    foreach ($attributes->whereStartsWith('x-model')->all() as $modelAttribute => $expression) {
        $attributes = $attributes->except($modelAttribute)->merge([
            $modelAttribute => preg_replace('/\bcurrent\b/', 'canvasColorBlock', $expression),
        ]);
    }
@endphp

<x-filament::input.wrapper class="fi-fo-color-picker" x-on:focus-input.stop="$el.querySelector('input')?.focus()">
    <div
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('color-picker', 'filament/forms') }}"
        x-data="{
            ...colorPickerFormComponent({ isAutofocused: false, isDisabled: false, isLive: false, isLiveDebounced: false, isLiveOnBlur: false, liveDebounce: null, state: null }),
            canvasColorBlock: current,
            canvasColorTimer: null,
            init() {
                this.$watch('current', block => {
                    const family = type => ['title', 'text'].includes(type) ? 'text' : type;
                    if (block &amp;&amp; family(block.type) === family(this.canvasColorBlock?.type)) this.canvasColorBlock = block;
                });
                if (this.state !== null &amp;&amp; this.state !== '') this.setState(this.state);
                this.$watch('state', value => {
                    clearTimeout(this.canvasColorTimer);
                    this.canvasColorTimer = setTimeout(() => {
                        if (this.$refs.picker &amp;&amp; CSS.supports('color', value)) this.$refs.picker.color = value;
                    }, 200);
                });
                this.$refs.input.addEventListener('change', event => this.setState(event.target.value));
                this.$refs.panel.addEventListener('color-changed', event => this.setState(event.detail.value));
            },
            destroy() { clearTimeout(this.canvasColorTimer); },
        }"
        x-modelable="state"
        x-id="['canvas-color']"
        x-on:keydown.esc="isOpen() && $event.stopPropagation()"
        x-on:focusout="if (isOpen() && ! $el.contains($event.relatedTarget)) $refs.panel.close()"
        data-canvas-local-color-picker
        {{ $attributes->class(['fi-input-wrp-content']) }}
    >
        <x-filament::input
            x-ref="input"
            x-model="state"
            x-bind:id="$id('canvas-color')"
            x-bind:aria-controls="$id('canvas-color') + '-panel'"
            x-bind:disabled="saving"
            x-on:focus="$refs.panel.open($refs.input)"
            x-on:keydown.enter.prevent.stop="togglePanelVisibility()"
            autocomplete="off"
            :placeholder="$placeholder"
        />
        <div
            aria-hidden="true"
            class="fi-fo-color-picker-preview my-auto me-3 size-5 shrink-0 rounded-full select-none"
            x-on:click="togglePanelVisibility()"
            x-bind:class="{ 'fi-empty': ! state }"
            x-bind:style="{ 'background-color': state }"
        ></div>
        <div
            x-cloak
            x-float.placement.bottom-start.offset.flip.shift="{ offset: 8 }"
            x-ref="panel"
            x-bind:id="$id('canvas-color') + '-panel'"
            role="dialog"
            aria-label="{{ __('filament-forms::components.color_picker.panel_label') }}"
            class="fi-fo-color-picker-panel"
        >
            <hex-color-picker x-ref="picker" color="#000000"></hex-color-picker>
        </div>
    </div>
</x-filament::input.wrapper>
