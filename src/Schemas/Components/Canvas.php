<?php

namespace EduardoRibeiroDev\FilamentCanvas\Schemas\Components;

use Closure;
use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Support\EditorConfiguration;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Embed the document editor in a Schema; it keeps its own save action. */
class Canvas extends Component
{
    protected string $view = 'filament-canvas::schemas.canvas';

    protected string $documentType;

    protected string|int|Closure $documentId;

    protected array $canvasOptions = [];

    protected Model|Closure|null $canvasRecord = null;

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

    public function record(Model|Closure $record): static
    {
        $this->canvasRecord = $record;

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
        $type = $this->documentType ?? null;
        $options = $this->canvasOptions;
        if ($this->canvasRecord !== null) {
            $record = $this->evaluate($this->canvasRecord);
            if (
                ! $record instanceof Model
                || ! $record instanceof CanvasOwner
                || ! $record->exists
                || $record->getKey() === null
            ) {
                throw new InvalidArgumentException('The canvas record must be a saved model implementing CanvasOwner.');
            }

            $type = $record::class;
            $id = $record->getKey();
            $options['model'] = $type;
        } else {
            $id = $this->evaluate($this->documentId);
        }
        $panelId = Filament::getCurrentPanel()?->getId();

        return [
            'documentType' => $type,
            'documentId' => $id,
            'panelId' => $panelId,
            'configuration' => $options === [] ? null : EditorConfiguration::encode($type, $id, $options, $panelId),
        ];
    }
}
