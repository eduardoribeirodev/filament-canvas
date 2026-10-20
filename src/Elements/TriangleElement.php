<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class TriangleElement extends ShapeElement
{
    public function type(): string
    {
        return 'triangle';
    }

    public function getLabel(): string|Htmlable|null
    {
        return __('filament-canvas::canvas.triangle');
    }

    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedPlay;
    }

    public function view(): string
    {
        return 'filament-canvas::elements.triangle';
    }
}
