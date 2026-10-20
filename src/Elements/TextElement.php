<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use EduardoRibeiroDev\FilamentCanvas\Forms\Components\RichTextEditor;
use EduardoRibeiroDev\FilamentCanvas\Support\RichText;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;

class TextElement extends CanvasElement
{
    public function __construct(protected string $name = 'text') {}

    public function type(): string
    {
        return $this->name;
    }

    public function getLabel(): string|Htmlable|null
    {
        return $this->name === 'title' ? __('filament-canvas::canvas.title') : __('filament-canvas::canvas.text');
    }

    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return $this->name === 'title' ? Heroicon::OutlinedH1 : Heroicon::OutlinedDocumentText;
    }

    public function view(): string
    {
        return 'filament-canvas::elements.text';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->textField(),
            TextInput::make('font_size')
                ->label(__('filament-canvas::canvas.font_size'))
                ->numeric()
                ->minValue(1)
                ->maxValue(100)
                ->default($this->name === 'title' ? 24 : 12)
                ->suffix('pt'),
            ColorPicker::make('color')
                ->regex('/^#(?:[a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})$/iD')
                ->label(__('filament-canvas::canvas.color'))
                ->default('#222222'),
            TextInput::make('leading')
                ->label(__('filament-canvas::canvas.line_height'))
                ->numeric()
                ->minValue(.5)
                ->maxValue(10)
                ->default(1.25),
            ColorPicker::make('background_color')
                ->regex('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD')
                ->label(__('filament-canvas::canvas.background_color')),
            TextInput::make('padding')
                ->label(__('filament-canvas::canvas.padding'))
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('pt')
                ->default(0),
            TextInput::make('border_radius')
                ->label(__('filament-canvas::canvas.corner_radius'))
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('pt')
                ->default(0),
            TextInput::make('columns')
                ->label(__('filament-canvas::canvas.text_columns'))
                ->integer()
                ->minValue(1)
                ->maxValue(12)
                ->default(1),
            TextInput::make('link_url')
                ->label(__('filament-canvas::canvas.link'))
                ->url()
                ->maxLength(2048),
        ])->columns(2);
    }

    protected function textField(): RichEditor
    {
        return RichTextEditor::make('rich_text')
            ->label(__('filament-canvas::canvas.text'))
            ->json()
            ->maxLength(20000)
            ->default([
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'attrs' => ['textAlign' => 'start'],
                    'content' => [array_filter([
                        'type' => 'text',
                        'text' => $this->name === 'title'
                            ? __('filament-canvas::canvas.new_title')
                            : __('filament-canvas::canvas.enter_text'),
                        'marks' => $this->name === 'title' ? [['type' => 'bold']] : null,
                    ], fn ($value) => $value !== null)],
                ]],
            ])
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'strike', 'highlight', 'textColor'],
                ['alignStart', 'alignCenter', 'alignEnd', 'alignJustify'],
                ['link', 'bulletList', 'orderedList'],
                ['undo', 'redo', 'clearFormatting'],
            ])
            ->floatingToolbars([])
            ->textColors([
                '#E60014' => __('filament-canvas::canvas.red'),
                '#111111' => __('filament-canvas::canvas.black'),
                '#666666' => __('filament-canvas::canvas.gray'),
                '#2563EB' => __('filament-canvas::canvas.blue'),
            ])
            ->customTextColors()
            ->columnSpanFull();
    }

    public function inlineEditorField(): RichEditor
    {
        $field = $this->textField();
        $source = clone $field;

        return $field->hiddenLabel()->toolbarButtons([])
            ->floatingToolbars(function (RichEditor $component) use ($source): array {
                $source->container($component->getContainer());
                $buttons = Arr::flatten($source->getToolbarButtons());
                foreach ($component->getTools() as $tool) {
                    $tool->editor($component);
                }

                return ['paragraph' => $buttons, 'heading' => $buttons];
            });
    }

    public function normalize(array $properties): array
    {
        return RichText::normalize($properties);
    }
}
