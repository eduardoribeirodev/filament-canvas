<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DrawingElement extends CanvasElement
{
    public function type(): string
    {
        return 'drawing';
    }

    public function label(): string
    {
        return 'Desenho';
    }

    public function icon(): string
    {
        return 'heroicon-o-pencil';
    }

    public function capabilities(): array
    {
        return ['drawing' => true];
    }

    public function view(): string
    {
        return 'filament-canvas::elements.drawing';
    }

    public function properties(Schema $schema): Schema
    {
        $fields = [];
        foreach (['x1' => ['Início horizontal', 0], 'y1' => ['Início vertical', 50], 'x2' => ['Fim horizontal', 100], 'y2' => ['Fim vertical', 50]] as $key => [$label, $default]) {
            $fields[] = TextInput::make('drawing.'.$key)->label($label)->numeric()->minValue(0)->maxValue(100)->suffix('%')->default($default);
        }

        return $schema->components([
            ColorPicker::make('drawing.stroke')->regex('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD')->label('Cor do traço')->default('#E60014'),
            TextInput::make('drawing.stroke_width')->label('Espessura')->numeric()->minValue(.1)->maxValue(20)->default(.7)->suffix('pt'),
            Select::make('drawing.pattern')->label('Traço')->options(['solid' => 'Contínuo', 'dashed' => 'Tracejado', 'dotted' => 'Pontilhado'])->default('solid')->native(false)->live(),
            TextInput::make('drawing.dash_length')->label('Comprimento do traço')->numeric()->minValue(.1)->maxValue(100)->default(4.25)->suffix('pt')->visible(fn (Get $get) => $get('drawing.pattern') === 'dashed'),
            TextInput::make('drawing.gap_length')->label('Intervalo')->numeric()->minValue(.1)->maxValue(100)->default(4.25)->suffix('pt')->visible(fn (Get $get) => $get('drawing.pattern') !== 'solid'),
            ...$fields,
        ])->columns(2);
    }

    public function validationRules(string $prefix = ''): array
    {
        return [...parent::validationRules($prefix), $prefix.'drawing' => ['nullable', 'array:x1,y1,x2,y2,stroke,stroke_width,pattern,dash_length,gap_length']];
    }

    public function viewData(array $dados, EditorContext $context, array $media = []): array
    {
        return ['drawing' => $dados['drawing'] ?? []];
    }
}
