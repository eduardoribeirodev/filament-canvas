@props(['label' => null, 'inline' => false])

@php
    $attributes = $attributes->class(['canvas-native-fields'])->merge([
        'x-id' => "['canvas-field']",
        'x-init' => "\$nextTick(() => { const control = \$el.querySelector('input:not([type=hidden]), select, textarea'); const label = \$el.querySelector('.fi-fo-field-label'); if (control && label) { control.id ||= \$id('canvas-field'); label.htmlFor = control.id; } })",
    ]);
@endphp

<x-filament-forms::field-wrapper :label="$label" {{ $attributes }}>
    @if ($inline)
        <x-slot name="labelPrefix">{{ $slot }}</x-slot>
    @else
        {{ $slot }}
    @endif
</x-filament-forms::field-wrapper>
