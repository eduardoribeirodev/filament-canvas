<label class="canvas-alignment-control" x-tooltip="{content:@js(__('filament-canvas::canvas.guides_hint')),theme:$store.theme}">
    <x-filament::input.checkbox x-model="alignmentSnap" @change="snapGuides=[]" />
    <span>{{ __('filament-canvas::canvas.guides') }}</span>
</label>
