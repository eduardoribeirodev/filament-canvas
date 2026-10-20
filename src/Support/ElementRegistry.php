<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Rendering\ElementMarkup;
use EduardoRibeiroDev\FilamentCanvas\Elements\DataTableElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DrawingElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TitleElement;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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
        return array_map(fn (ElementType $element) => (string) ($element->getLabel() ?? $element->type()), $this->elements);
    }

    public function icons(): array
    {
        return array_map(fn (ElementType $element) => $element->getIcon(), $this->elements);
    }

    public static function mediaCollectionMetadata(HasElementMedia $element): array
    {
        return array_map(fn (array $collection) => array_intersect_key($collection, array_flip([
            'label', 'accepted_mime_types', 'max_files', 'max_size', 'reference_field',
        ])), $element->mediaCollections());
    }

    public static function kind(ElementType $element): string
    {
        return match (true) {
            $element instanceof TextElement => 'text',
            $element instanceof ImageElement => 'image',
            $element instanceof DrawingElement => 'drawing',
            $element instanceof DataTableElement => 'table',
            default => 'element',
        };
    }

    /** Compile each registered type automatically, including custom views. */
    public function templates(): array
    {
        return array_map(fn (ElementType $element) => ElementMarkup::template($element), $this->elements);
    }

    /** The editor derives behavior from class inheritance and media collection declarations. */
    public function metadataForEditor(): array
    {
        return array_map(fn (ElementType $element): array => [
            'label' => (string) ($element->getLabel() ?? $element->type()),
            'class' => $element::class,
            'icon' => ($element->getIcon() instanceof \BackedEnum ? $element->getIcon()->value : (string) $element->getIcon()),
            'kind' => self::kind($element),
            'toolbar' => method_exists($element, 'showInToolbar') ? $element->showInToolbar() : true,
            'media_collections' => $element instanceof HasElementMedia ? self::mediaCollectionMetadata($element) : [],
        ], $this->elements);
    }
}
