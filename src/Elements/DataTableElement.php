<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class DataTableElement extends CanvasElement
{
    public function __construct(protected string $name = 'data-table') {}

    public function type(): string
    {
        return $this->name;
    }

    public function getLabel(): string|Htmlable|null
    {
        return __('filament-canvas::canvas.table');
    }

    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedTableCells;
    }

    public function showInToolbar(): bool
    {
        return $this->name === 'data-table';
    }

    public function view(): string
    {
        return 'filament-canvas::elements.data-table';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Repeater::make('columns')
                ->label(__('filament-canvas::canvas.columns'))
                ->schema([
                    TextInput::make('label')
                        ->rules(['string'])
                        ->label(__('filament-canvas::canvas.column_heading'))
                        ->required()
                        ->maxLength(255),
                    Hidden::make('field')
                        ->rules(['string', 'max:255'])
                        ->distinct()
                        ->required()
                        ->default(fn () => 'column_'.Str::lower(Str::random(12)))
                        ->afterStateHydrated(fn (Hidden $component, $state) => $component->state(
                            $state === null || $state === ''
                                ? 'column_'.Str::lower(Str::random(12))
                                : $state,
                        )),
                ])
                ->default([['label' => __('filament-canvas::canvas.column'), 'field' => 'value']])
                ->required()
                ->minItems(1)
                ->maxItems(30)
                ->reorderableWithDragAndDrop()
                ->addActionLabel(__('filament-canvas::canvas.add_column'))
                ->itemLabel(fn (array $state): string => $state['label'] ?? __('filament-canvas::canvas.new_column'))
                ->live(),
            Repeater::make('rows')
                ->rules(['present'])
                ->label(__('filament-canvas::canvas.rows'))
                ->schema(function (Get $get): array {
                    $columns = $get('columns') ?? [];

                    if (! is_array($columns)) {
                        return [];
                    }

                    return array_values(array_map(
                        fn (array $column) => TextInput::make($column['field'])
                            ->label(is_string($column['label'] ?? null)
                                ? $column['label']
                                : __('filament-canvas::canvas.column'))
                            ->nullable()
                            ->rules(['string'])
                            ->maxLength(2000),
                        array_filter(
                            $columns,
                            fn ($column) => is_array($column)
                                && is_string($column['field'] ?? null)
                                && $column['field'] !== '',
                        ),
                    ));
                })
                ->default([['value' => __('filament-canvas::canvas.example')]])
                ->maxItems(1000)
                ->reorderableWithDragAndDrop()
                ->addActionLabel(__('filament-canvas::canvas.add_row'))
                ->columns(2),
            TextInput::make('font_size')
                ->label(__('filament-canvas::canvas.font_size'))
                ->numeric()
                ->minValue(1)
                ->maxValue(100)
                ->default(12)
                ->suffix('pt'),
        ]);
    }
}
