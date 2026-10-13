<?php

namespace EduardoRibeiroDev\FilamentCanvas\Forms\Components;

use EduardoRibeiroDev\FilamentCanvas\Forms\StateCasts\RichTextEditorStateCast;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\StateCasts\RichEditorStateCast;

class RichTextEditor extends RichEditor
{
    public function getDefaultStateCasts(): array
    {
        return array_map(
            fn ($cast) => $cast instanceof RichEditorStateCast ? new RichTextEditorStateCast($this) : $cast,
            parent::getDefaultStateCasts(),
        );
    }
}
