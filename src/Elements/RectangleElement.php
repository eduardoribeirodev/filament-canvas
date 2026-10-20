<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class RectangleElement extends ShapeElement
{
    public function type(): string
    {
        return 'rectangle';
    }

    public function getLabel(): string|Htmlable|null
    {
        return __('filament-canvas::canvas.rectangle');
    }

    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedRectangleGroup;
    }

    public function view(): string
    {
        return 'filament-canvas::elements.rectangle';
    }
}
