<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Elements\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DataTableElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DrawingElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TitleElement;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use ReflectionMethod;

final class ElementRegistry
{
    private static array $ownerScopes = [];

    public static function forOwner(Model&CanvasOwner $owner): self
    {
        return self::$ownerScopes[$owner::class] ?? $owner->canvasElementRegistry();
    }

    public static function using(string $ownerModel, self $elements, callable $operation): mixed
    {
        $previous = self::$ownerScopes[$ownerModel] ?? null;
        self::$ownerScopes[$ownerModel] = $elements;
        try {
            return $operation();
        } finally {
            if ($previous === null) {
                unset(self::$ownerScopes[$ownerModel]);
            } else {
                self::$ownerScopes[$ownerModel] = $previous;
            }
        }
    }

    /** @var array<string, ElementType> */
    private array $elements = [];

    /** @param array<ElementType|class-string<ElementType>> $elements */
    public static function from(array $elements): self
    {
        $registry = new self;
        foreach ($elements as $element) {
            $registry->register($element);
        }

        return $registry;
    }

    public static function defaults(): self
    {
        $registry = new self;
        foreach ([new TitleElement, new TextElement, new ImageElement, new ImageElement('image'), new ImageElement('photo'), new DrawingElement, new DataTableElement, new DataTableElement('table')] as $element) {
            $registry->register($element);
        }

        return $registry;
    }

    /** Share configured element classes between the panel and Eloquent/CLI saves. */
    public static function configured(): self
    {
        $registry = self::defaults();
        foreach (config('filament-canvas.elements', []) as $element) {
            $registry->register($element);
        }

        return $registry;
    }

    /** @param ElementType|class-string<ElementType> $element */
    public function register(string|ElementType $element): self
    {
        $element = is_string($element) ? app($element) : $element;
        if (! $element instanceof ElementType) {
            throw new InvalidArgumentException('Canvas elements must implement ElementType.');
        }
        $this->elements[$element->type()] = $element;

        return $this;
    }

    public function only(array $types): self
    {
        $registry = new self;
        foreach ($types as $type) {
            $registry->register($this->get($type));
        }

        return $registry;
    }

    public function get(string $type): ElementType
    {
        return $this->elements[$type] ?? throw new InvalidArgumentException("Unknown canvas element: {$type}");
    }

    public function types(): array
    {
        return array_keys($this->elements);
    }

    public function labels(): array
    {
        return array_map(fn (ElementType $element) => $element->label(), $this->elements);
    }

    /** Metadata has no duplicated defaults; creation fills the element's native schema. */
    public function metadataForEditor(): array
    {
        return array_map(function (ElementType $element): array {
            $capabilities = $element->capabilities();
            $usesDefaultRendering = (new ReflectionMethod($element, 'render'))->getDeclaringClass()->getName() === CanvasElement::class;
            $kind = null;
            foreach (['image', 'text', 'drawing', 'table'] as $candidate) {
                if ($capabilities[$candidate] ?? false) {
                    $kind = $candidate;
                    break;
                }
            }

            return array_filter([
                'label' => $element->label(), 'icon' => $element->icon(),
                'capabilities' => $capabilities, 'kind' => $kind, 'toolbar' => $capabilities['toolbar'] ?? true,
                'local_renderer' => array_key_exists('local_renderer', $capabilities) ? $capabilities['local_renderer'] : ($usesDefaultRendering ? match ($element->view()) {
                    'filament-canvas::elements.text' => 'text',
                    'filament-canvas::elements.image' => 'image',
                    'filament-canvas::elements.drawing' => 'drawing',
                    'filament-canvas::elements.data-table' => 'table',
                    default => false,
                } : false),
                'media_collections' => $element instanceof HasElementMedia ? $element->mediaCollections() : null,
            ], fn ($value) => $value !== null);
        }, $this->elements);
    }
}
