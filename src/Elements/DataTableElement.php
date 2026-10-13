<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

class DataTableElement extends CanvasElement
{
    public function __construct(protected string $name = 'data-table') {}

    public function type(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'Tabela';
    }

    public function icon(): string
    {
        return 'heroicon-o-table-cells';
    }

    public function capabilities(): array
    {
        return ['table' => true, 'toolbar' => $this->name === 'data-table'];
    }

    public function view(): string
    {
        return 'filament-canvas::elements.data-table';
    }

    public function properties(Schema $schema): Schema
    {
        return $schema->components([
            Repeater::make('columns')->label('Colunas')->schema([
                TextInput::make('label')->rules(['string'])->label('Título da coluna')->required()->maxLength(255),
                Hidden::make('field')->rules(['string', 'max:255'])->required()->default(fn () => 'column_'.Str::lower(Str::random(12)))
                    ->afterStateHydrated(fn (Hidden $component, $state) => $component->state($state === null || $state === '' ? 'column_'.Str::lower(Str::random(12)) : $state)),
            ])->default([['label' => 'Coluna', 'field' => 'value']])->required()->minItems(1)->maxItems(30)->reorderableWithDragAndDrop()->addActionLabel('Adicionar coluna')->itemLabel(fn (array $state): string => $state['label'] ?? 'Nova coluna')->live(),
            Repeater::make('rows')->label('Linhas')->schema(function (Get $get): array {
                $columns = $get('columns') ?? [];
                if (! is_array($columns)) {
                    return [];
                }
                return array_values(array_map(fn (array $column) => TextInput::make($column['field'])
                    ->label(is_string($column['label'] ?? null) ? $column['label'] : 'Coluna')->nullable()->rules(['string'])->maxLength(2000),
                    array_filter($columns, fn ($column) => is_array($column) && is_string($column['field'] ?? null) && $column['field'] !== '')));
            })->default([['value' => 'Exemplo']])->maxItems(1000)->reorderableWithDragAndDrop()
                ->addActionLabel('Adicionar linha')->columns(2),
            TextInput::make('font_size')->label('Tamanho da fonte')->numeric()->minValue(1)->maxValue(100)->default(12)->suffix('pt'),
        ]);
    }

    public function validationRules(string $prefix = ''): array
    {
        return [...parent::validationRules($prefix),
            $prefix.'columns' => ['required', 'array', 'min:1', 'max:30', function ($attribute, $value, $fail): void {
                if (! is_array($value)) {
                    return;
                }
                $keys = [];
                foreach ($value as $column) {
                    $field = is_array($column) ? ($column['field'] ?? null) : null;
                    if (! is_string($field) || $field === '' || mb_strlen($field) > 255) {
                        $fail('Cada coluna precisa de uma identificação válida.');
                        return;
                    }
                    if (in_array($field, $keys, true)) {
                        $fail('As identificações das colunas devem ser diferentes.');
                        return;
                    }
                    $keys[] = $field;
                }
            }],
            $prefix.'columns.*' => ['array'],
            $prefix.'columns.*.label' => ['required', 'string', 'max:255'],
            $prefix.'columns.*.field' => ['required', 'string', 'max:255', 'distinct'],
            $prefix.'rows' => ['present', 'array', 'max:1000'],
            $prefix.'rows.*' => ['array'],
            $prefix.'rows.*.*' => ['nullable', 'string', 'max:2000']];
    }
}
