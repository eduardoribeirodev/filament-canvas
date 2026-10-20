<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class LineElement extends CanvasElement
{
    public function type(): string
    {
        return 'line';
    }

    public function getLabel(): string|Htmlable|null
    {
        return __('filament-canvas::canvas.line');
    }

    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedMinus;
    }

    public function view(): string
    {
        return 'filament-canvas::elements.line';
    }

    public function form(Schema $schema): Schema
    {
        $fields = [];
        $coordinates = [
            'x1' => [__('filament-canvas::canvas.horizontal_start'), 0],
            'y1' => [__('filament-canvas::canvas.vertical_start'), 50],
            'x2' => [__('filament-canvas::canvas.horizontal_end'), 100],
            'y2' => [__('filament-canvas::canvas.vertical_end'), 50],
        ];

        foreach ($coordinates as $key => [$label, $default]) {
            $fields[] = TextInput::make('drawing.'.$key)
                ->label($label)
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->default($default);
        }

        return $schema->components([
            ColorPicker::make('drawing.stroke')
                ->regex('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD')
                ->label(__('filament-canvas::canvas.stroke_color'))
                ->default('#E60014'),
            TextInput::make('drawing.stroke_width')
                ->label(__('filament-canvas::canvas.stroke_width'))
                ->numeric()
                ->minValue(.1)
                ->maxValue(20)
                ->default(.7)
                ->suffix('pt'),
            Select::make('drawing.pattern')
                ->label(__('filament-canvas::canvas.stroke'))
                ->options([
                    'solid' => __('filament-canvas::canvas.solid'),
                    'dashed' => __('filament-canvas::canvas.dashed'),
                    'dotted' => __('filament-canvas::canvas.dotted'),
                ])
                ->default('solid')
                ->native(false)
                ->live(),
            TextInput::make('drawing.dash_length')
                ->label(__('filament-canvas::canvas.dash_length'))
                ->numeric()
                ->minValue(.1)
                ->maxValue(100)
                ->default(4.25)
                ->suffix('pt')
                ->visibleJs("\$get('drawing.pattern') === 'dashed'"),
            TextInput::make('drawing.gap_length')
                ->label(__('filament-canvas::canvas.gap_length'))
                ->numeric()
                ->minValue(.1)
                ->maxValue(100)
                ->default(4.25)
                ->suffix('pt')
                ->visibleJs("\$get('drawing.pattern') !== 'solid'"),
            ...$fields,
        ])->columns(2);
    }
}
