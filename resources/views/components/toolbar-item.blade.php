@props(['label', 'icon' => null])

<li class="fi-sidebar-item fi-sidebar-item-has-url canvas-toolbar-item">
    <button
        {{ $attributes->class(['fi-sidebar-item-btn', 'canvas-toolbar-item-btn'])->merge([
            'type' => 'button',
            'aria-label' => $label,
            'x-bind:disabled' => 'saving || adding',
        ]) }}
        x-tooltip="{
            content: @js($label),
            placement: document.dir === 'rtl' ? 'left' : 'right',
            theme: $store.theme,
            allowHTML: false,
        }"
    >
        {{ \Filament\Support\generate_icon_html(
            $icon ?: 'heroicon-o-plus',
            attributes: (new \Filament\Support\View\ComponentAttributeBag)->class(['fi-sidebar-item-icon']),
            size: \Filament\Support\Enums\IconSize::Large,
        ) }}
    </button>
</li>
