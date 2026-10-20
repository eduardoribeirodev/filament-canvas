@php($zoomId = 'canvas-zoom-'.$this->getId())

<div class="canvas-zoom-control" data-canvas-zoom wire:ignore
    @canvas-zoom-slider-ready.stop="mountZoomSlider($event.detail.element)">
    <span id="{{ $zoomId }}-label">{{ __('filament-canvas::canvas.zoom') }}</span>
    <div class="canvas-zoom-field">
        <div
            id="{{ $zoomId }}"
            role="group"
            aria-labelledby="{{ $zoomId }}-label"
            class="fi-fo-slider"
            data-canvas-zoom-slider
            x-load
            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('slider', 'filament/forms') }}"
            x-data="sliderFormComponent({
                behavior: 'tap',
                decimalPlaces: 0,
                fillTrack: [true, false],
                isDisabled: saving,
                isRtl: false,
                isVertical: false,
                minValue: minZoom * 100,
                maxValue: maxZoom * 100,
                state: Math.round(zoom * 100),
                step: 1,
                tooltips: false,
            })"
            x-init="$nextTick(() => $dispatch('canvas-zoom-slider-ready', {element: $el}))"
        ></div>
    </div>
    <output class="canvas-zoom-output" x-text="Math.round(zoom * 100) + '%'" aria-label="{{ __('filament-canvas::canvas.current_zoom') }}"></output>
</div>
