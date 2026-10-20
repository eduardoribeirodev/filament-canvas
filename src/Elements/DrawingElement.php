<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class DrawingElement extends ShapeElement
{
    public function type(): string
    {
        return 'drawing';
    }

    public function getLabel(): string|Htmlable|null
    {
        return __('filament-canvas::canvas.drawing');
    }

    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedPencil;
    }

    public function view(): string
    {
        return 'filament-canvas::elements.drawing';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ...parent::form($schema)->getComponents(),
            Toggle::make('drawing.closed')->label(__('filament-canvas::canvas.closed_shape'))->default(false),
            Repeater::make('drawing.points')->label(__('filament-canvas::canvas.points'))->minItems(2)->maxItems(300)
                ->default([['x' => 0, 'y' => 0], ['x' => 100, 'y' => 100]])
                ->rules([fn ($get) => function ($attribute, $value, $fail) use ($get): void {
                    if ($get('drawing.closed') && count($value) < 3) {
                        $fail(__('filament-canvas::canvas.drawing_min_points'));
                    }
                }])
                ->schema([
                    TextInput::make('x')->label('X')->numeric()->required()->minValue(0)->maxValue(100)->suffix('%'),
                    TextInput::make('y')->label('Y')->numeric()->required()->minValue(0)->maxValue(100)->suffix('%'),
                ])->columns(2)->columnSpanFull(),
        ]);
    }
}
