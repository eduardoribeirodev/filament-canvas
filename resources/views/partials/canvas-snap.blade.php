<x-filament::icon-button type="button" color="gray" icon="heroicon-o-viewfinder-circle"
    class="fc-guide-toggle"
    label="{{ __('filament-canvas::canvas.guides') }}"
    title="{{ __('filament-canvas::canvas.guides_hint') }}"
    x-bind:aria-pressed="alignmentSnap"
    @click="alignmentSnap = !alignmentSnap; snapGuides = []" />
