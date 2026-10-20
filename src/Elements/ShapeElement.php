<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use Filament\Forms\Components\ColorPicker;
use Filament\Schemas\Schema;

abstract class ShapeElement extends LineElement
{
    public function form(Schema $schema): Schema
    {
        $fields = parent::form($schema)->getComponents();
        $fields = array_filter($fields, fn ($field) => ! in_array($field->getName(), ['drawing.x1', 'drawing.y1', 'drawing.x2', 'drawing.y2'], true));

        return $schema->components([
            ...$fields,
            ColorPicker::make('drawing.fill')->label(__('filament-canvas::canvas.fill_color'))
                ->regex('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD')->nullable(),
        ]);
    }
}
