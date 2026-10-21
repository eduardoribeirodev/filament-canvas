<?php

namespace EduardoRibeiroDev\FilamentCanvas;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Contracts\DocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use EduardoRibeiroDev\FilamentCanvas\Contracts\PreviewRenderer;
use EduardoRibeiroDev\FilamentCanvas\Persistence\EloquentDocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Rendering\CanvasRenderer;
use EduardoRibeiroDev\FilamentCanvas\Support\CanvasElements;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CanvasPlugin implements Plugin
{
    private array $stores = [];

    private array $renderers = [];

    private array $views = [];

    private array $documentElementTypes = [];

    private array $elementDefinitions;

    private ?array $defaultElements = null;

    private array $extraElementClasses = [];

    private string $templateView = 'filament-canvas::templates.blank';

    private string $defaultEditorView = 'filament-canvas::editor';

    public function __construct()
    {
        $this->elementDefinitions = CanvasElements::configured();
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filament-canvas';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    public function documentStore(string $type, string|DocumentStore $store): static
    {
        $this->stores[$type] = $store;

        return $this;
    }

    /** Use the package's polymorphic element model and persistence. */
    public function documentModel(string $type, string $model): static
    {
        if (! is_a($model, Model::class, true) || ! is_a($model, CanvasOwner::class, true)) {
            throw new InvalidArgumentException('The document model must implement CanvasOwner.');
        }

        $elements = (new $model)->availableCanvasElements();
        foreach (CanvasElements::types($elements) as $elementType) {
            $this->elementDefinitions[] = CanvasElements::resolve($elements, $elementType);
        }
        $this->documentElementTypes[$type] ??= CanvasElements::types($elements);

        return $this->documentStore($type, new EloquentDocumentStore($model));
    }

    public function registerElements(array $elements): static
    {
        foreach ($elements as $element) {
            $this->element($element);
        }

        return $this;
    }

    public function previewRenderer(string $type, string|PreviewRenderer $renderer): static
    {
        $this->renderers[$type] = $renderer;

        return $this;
    }

    public function editorView(string $type, ?string $view = null): static
    {
        if ($view === null) {
            $this->defaultEditorView = $type;
        } else {
            $this->views[$type] = $view;
        }

        return $this;
    }

    public function getEditorView(string $type): string
    {
        return $this->views[$type] ?? $this->defaultEditorView;
    }

    public function element(string|ElementType $element): static
    {
        $this->elementDefinitions[] = $element;
        $this->extraElementClasses[] = $element;

        return $this;
    }

    public function documentElements(string $documentType, array $types): static
    {
        $this->documentElementTypes[$documentType] = $types;

        return $this;
    }

    /** An array replaces the available types; no argument returns their element list. */
    public function elements(array|string|null $documentType = null): array|static
    {
        if (is_array($documentType)) {
            $this->defaultElements = $documentType;
            $this->elementDefinitions = CanvasElements::list([...$documentType, ...$this->extraElementClasses]);

            return $this;
        }

        return isset($this->documentElementTypes[$documentType])
            ? CanvasElements::only($this->elementDefinitions, $this->documentElementTypes[$documentType])
            : $this->elementDefinitions;
    }

    public function extraElements(array $elements): static
    {
        $this->extraElementClasses = [...$this->extraElementClasses, ...$elements];
        foreach ($elements as $element) {
            $this->elementDefinitions[] = $element;
        }

        return $this;
    }

    public function template(string $view): static
    {
        $this->templateView = $view;

        return $this;
    }

    public function getTemplate(): string
    {
        return $this->templateView;
    }

    /** The Resource supplies the model. This clone never changes panel defaults. */
    public function forModel(string $type, string $model, array $options = []): static
    {
        if (! is_a($model, Model::class, true) || ! is_a($model, CanvasOwner::class, true)) {
            throw new InvalidArgumentException('The Resource model must implement CanvasOwner and may use HasCanvas.');
        }
        $plugin = clone $this;
        $owner = new $model;
        $selected = $options['elements']
            ?? (method_exists($owner, 'canvasElementsAvailable') ? $owner->canvasElementsAvailable() : null)
            ?? $this->defaultElements;
        if ($selected === null) {
            $definitions = CanvasElements::configured();
            // Owner definitions keep historical/domain types readable outside the panel.
            $ownerElements = (new $model)->availableCanvasElements();
            foreach (CanvasElements::types($ownerElements) as $elementType) {
                $definitions[] = CanvasElements::resolve($ownerElements, $elementType);
            }
        } else {
            $definitions = CanvasElements::list($selected);
        }
        foreach ($options['extraElements'] ?? (array_key_exists('elements', $options) ? [] : $this->extraElementClasses) as $element) {
            $definitions[] = $element;
        }
        $plugin->elementDefinitions = $definitions;
        unset($plugin->documentElementTypes[$type], $plugin->renderers[$type]);
        $plugin->stores[$type] = new EloquentDocumentStore($model, $definitions);
        if (isset($options['template'])) {
            $plugin->template($options['template']);
        } elseif (method_exists($owner, 'canvasTemplate') && $owner->canvasTemplate() !== null) {
            $plugin->template($owner->canvasTemplate());
        }
        if (isset($options['editorView'])) {
            $plugin->editorView($type, $options['editorView']);
        }

        return $plugin;
    }

    public function forEditor(string $type, array $options): static
    {
        if (isset($options['model'])) {
            return $this->forModel($type, $options['model'], $options);
        }
        $plugin = clone $this;
        $definitions = isset($options['elements']) ? CanvasElements::list($options['elements']) : $this->elements($type);
        foreach ($options['extraElements'] ?? [] as $element) {
            $definitions[] = $element;
        }
        $plugin->elementDefinitions = $definitions;
        unset($plugin->documentElementTypes[$type]);
        if (($plugin->stores[$type] ?? null) instanceof EloquentDocumentStore) {
            $plugin->stores[$type] = $plugin->stores[$type]->withElements($definitions);
        }
        if (isset($options['template'])) {
            $plugin->template($options['template']);
            unset($plugin->renderers[$type]);
        }
        if (isset($options['editorView'])) {
            $plugin->editorView($type, $options['editorView']);
        }

        return $plugin;
    }

    public function resolveDocumentStore(string $type): DocumentStore
    {
        $store = $this->stores[$type] ?? throw new InvalidArgumentException("No document store registered for {$type}.");
        $resolved = is_string($store) ? app($store) : $store;
        if (! $resolved instanceof DocumentStore) {
            throw new InvalidArgumentException('The document store must implement DocumentStore.');
        }

        return $resolved;
    }

    public function resolveRenderer(string $type): CanvasRenderer
    {
        return new CanvasRenderer($this->elements($type), $this->templateView, $this->stores[$type] ?? null);
    }

    public function resolvePreviewRenderer(string $type): PreviewRenderer
    {
        $renderer = $this->renderers[$type] ?? $this->resolveRenderer($type);
        $resolved = is_string($renderer) ? app($renderer) : $renderer;
        if (! $resolved instanceof PreviewRenderer) {
            throw new InvalidArgumentException('The renderer must implement PreviewRenderer.');
        }

        return $resolved;
    }
}
