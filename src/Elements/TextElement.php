<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use EduardoRibeiroDev\FilamentCanvas\Forms\Components\RichTextEditor;
use EduardoRibeiroDev\FilamentCanvas\Support\RichText;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

class TextElement extends CanvasElement
{
    public function __construct(protected string $name = 'text') {}

    public function type(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->name === 'title' ? 'Título' : 'Texto';
    }

    public function icon(): string
    {
        return $this->name === 'title' ? 'heroicon-o-h1' : 'heroicon-o-document-text';
    }

    public function capabilities(): array
    {
        return ['text' => true, 'inline_text' => true];
    }

    public function view(): string
    {
        return 'filament-canvas::elements.text';
    }

    public function properties(Schema $schema): Schema
    {
        return $schema->components([
            $this->textField(),
            TextInput::make('font_size')->label('Tamanho da fonte')->numeric()->minValue(1)->maxValue(100)
                ->default($this->name === 'title' ? 24 : 12)->suffix('pt'),
            ColorPicker::make('color')->regex('/^#(?:[a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})$/iD')->label('Cor')->default('#222222'),
            TextInput::make('leading')->label('Entrelinhas')->numeric()->minValue(.5)->maxValue(10)->default(1.25),
            ColorPicker::make('background_color')->regex('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD')->label('Cor de fundo'),
            TextInput::make('padding')->label('Espaçamento interno')->numeric()->minValue(0)->maxValue(100)->suffix('pt')->default(0),
            TextInput::make('border_radius')->label('Arredondamento')->numeric()->minValue(0)->maxValue(100)->suffix('pt')->default(0),
            TextInput::make('columns')->label('Colunas de texto')->integer()->minValue(1)->maxValue(12)->default(1),
            TextInput::make('link_url')->label('Link')->url()->maxLength(2048),
        ])->columns(2);
    }

    protected function textField(): RichEditor
    {
        return RichTextEditor::make('rich_text')->label('Texto')->json()->maxLength(20000)
            ->default(RichText::migrateTypography([
                'text' => $this->name === 'title' ? 'Novo título' : 'Digite seu texto',
                'bold' => $this->name === 'title',
                'align' => 'left',
            ])['rich_text'])
            ->beforeStateDehydrated(function (RichEditor $component, Get $get): void {
                if ($component->getRawState() !== null) {
                    return;
                }
                $content = array_filter([
                    'html' => $get('html'), 'text' => $get('text'),
                    'align' => $get('align') ?? 'center', 'bold' => $get('bold'), 'italic' => $get('italic'),
                ], fn ($value) => $value !== null);
                if ($content !== []) {
                    $component->state(RichText::migrateTypography($content)['rich_text']);
                }
            }, shouldUpdateValidatedStateAfter: true)
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'strike', 'highlight', 'textColor'],
                ['alignStart', 'alignCenter', 'alignEnd', 'alignJustify'],
                ['link', 'bulletList', 'orderedList'], ['undo', 'redo', 'clearFormatting'],
            ])->floatingToolbars([])->textColors(['#E60014' => 'Vermelho', '#111111' => 'Preto', '#666666' => 'Cinza', '#2563EB' => 'Azul'])->customTextColors()->columnSpanFull();
    }

    /** Reuse this element's native tools when editing text on the canvas. */
    public function inlineEditorField(): RichEditor
    {
        $field = $this->textField();
        $source = clone $field;

        return $field->hiddenLabel()->toolbarButtons([])
            ->floatingToolbars(function (RichEditor $component) use ($source): array {
                // Tool callbacks may depend on schema state, unavailable before mounting.
                $source->container($component->getContainer());
                $buttons = Arr::flatten($source->getToolbarButtons());
                // Cloned native tool objects must still route actions to the mounted field.
                foreach ($component->getTools() as $tool) {
                    $tool->editor($component);
                }

                return ['paragraph' => $buttons, 'heading' => $buttons];
            });
    }

    public function normalize(array $dados): array
    {
        return RichText::migrateTypography($dados, title: $this->name === 'title');
    }

    public function validationRules(string $prefix = ''): array
    {
        return [...parent::validationRules($prefix), ...RichText::rules($prefix),
            // Accept old documents while their global formatting is moved into rich_text.
            $prefix.'align' => ['nullable', 'in:left,center,right,justify'],
            $prefix.'bold' => ['nullable', 'boolean'],
            $prefix.'italic' => ['nullable', 'boolean'],
            $prefix.'font' => ['nullable', 'string', 'max:100']];
    }
}
