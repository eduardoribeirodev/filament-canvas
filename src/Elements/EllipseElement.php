<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class EllipseElement extends ShapeElement
{
    public function type(): string
    {
        return 'ellipse';
    }

    public function getLabel(): string|Htmlable|null
    {
        return __('filament-canvas::canvas.ellipse');
    }

    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedStopCircle;
    }

    public function view(): string
    {
        return 'filament-canvas::elements.ellipse';
    }
}
