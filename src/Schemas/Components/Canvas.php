<?php

namespace EduardoRibeiroDev\FilamentCanvas\Schemas\Components;

use Closure;
use EduardoRibeiroDev\FilamentCanvas\Support\EditorConfiguration;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;

/** Embed the document editor in a Schema; it keeps its own save action. */
class Canvas extends Component
{
    protected string $view = 'filament-canvas::schemas.canvas';

    protected string $documentType;

    protected string|int|Closure $documentId;

    protected array $canvasOptions = [];

    public static function make(): static
    {
        $component = app(static::class);
        $component->configure();

        return $component;
    }

    public function document(string $type, string|int|Closure $id): static
    {
        $this->documentType = $type;
        $this->documentId = $id;

        return $this;
    }

    public function owner(string $model, string|int|Closure $id, ?string $type = null): static
    {
        $this->canvasOptions['model'] = $model;

        return $this->document($type ?? $model, $id);
    }

    public function template(string $view): static
    {
        $this->canvasOptions['template'] = $view;

        return $this;
    }

    public function elements(array $elements): static
    {
        $this->canvasOptions['elements'] = $elements;

        return $this;
    }

    public function extraElements(array $elements): static
    {
        $this->canvasOptions['extraElements'] = $elements;

        return $this;
    }

    public function assetLibrary(string $library): static
    {
        $this->canvasOptions['assetLibrary'] = $library;

        return $this;
    }

    public function editorView(string $view): static
    {
        $this->canvasOptions['editorView'] = $view;

        return $this;
    }

    public function options(array $options): static
    {
        $this->canvasOptions = array_replace($this->canvasOptions, $options);

        return $this;
    }

    public function getEditorParameters(): array
    {
        $id = $this->evaluate($this->documentId);

        return [
            'documentType' => $this->documentType, 'documentId' => $id,
            'panelId' => Filament::getCurrentPanel()?->getId(),
            'configuration' => $this->canvasOptions === [] ? null : EditorConfiguration::encode($this->documentType, $id, $this->canvasOptions, Filament::getCurrentPanel()?->getId()),
        ];
    }
}
