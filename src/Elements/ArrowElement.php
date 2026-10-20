<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ArrowElement extends ShapeElement
{
    public function type(): string
    {
        return 'arrow';
    }

    public function getLabel(): string|Htmlable|null
    {
        return __('filament-canvas::canvas.arrow');
    }

    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedArrowLongRight;
    }

    public function view(): string
    {
        return 'filament-canvas::elements.arrow';
    }
}
