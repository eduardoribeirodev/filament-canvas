<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Elements\ArrowElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DataTableElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DrawingElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ElementGroup;
use EduardoRibeiroDev\FilamentCanvas\Elements\EllipseElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\LineElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\RectangleElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ShapeElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TitleElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TriangleElement;
use EduardoRibeiroDev\FilamentCanvas\Rendering\ElementMarkup;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class CanvasElements
{
    private static array $ownerScopes = [];

    public static function forOwner(Model&CanvasOwner $owner): array
    {
        return self::$ownerScopes[$owner::class] ?? $owner->availableCanvasElements();
    }

    public static function using(string $model, array $elements, callable $operation): mixed
    {
        $previous = self::$ownerScopes[$model] ?? null;
        self::$ownerScopes[$model] = $elements;
        try {
            return $operation();
        } finally {
            if ($previous === null) {
                unset(self::$ownerScopes[$model]);
            } else {
                self::$ownerScopes[$model] = $previous;
            }
        }
    }

    public static function list(array $elements): array
    {
        self::all($elements);

        return $elements;
    }

    public static function defaults(): array
    {
        return [new TitleElement, new TextElement, new ImageElement, new ImageElement('image'), new ImageElement('photo'), new DrawingElement, new LineElement, new RectangleElement, new EllipseElement, new TriangleElement, new ArrowElement, new DataTableElement, new DataTableElement('table')];
    }

    public static function configured(): array
    {
        return [...self::defaults(), ...config('filament-canvas.elements', [])];
    }

    public static function all(array $elements): array
    {
        $resolved = [];
        foreach ($elements as $element) {
            if ($element instanceof ElementGroup) {
                foreach ($element->resolveElements() as $member) {
                    $resolved[$member->type()] = $member;
                }

                continue;
            }
            $element = is_string($element) ? app($element) : $element;
            if (! $element instanceof ElementType) {
                throw new InvalidArgumentException('Canvas elements must implement ElementType.');
            }
            $resolved[$element->type()] = $element;
        }

        return $resolved;
    }

    public static function resolve(array $elements, string $type): ElementType
    {
        $match = null;
        foreach ($elements as $definition) {
            $members = $definition instanceof ElementGroup ? $definition->resolveElements() : [$definition];
            foreach ($members as $element) {
                $element = is_string($element) ? app($element) : $element;
                if (! $element instanceof ElementType) {
                    throw new InvalidArgumentException('Canvas elements must implement ElementType.');
                }
                if ($element->type() === $type) {
                    $match = $element;
                }
            }
        }
        if ($match !== null) {
            return $match;
        }
        throw new InvalidArgumentException("Unknown canvas element: {$type}");
    }

    public static function types(array $elements): array
    {
        return array_keys(self::all($elements));
    }

    public static function only(array $elements, array $types): array
    {
        foreach ($types as $type) {
            self::resolve($elements, $type);
        }
        $result = [];
        foreach ($elements as $element) {
            if ($element instanceof ElementGroup) {
                $members = array_values(array_filter($element->resolveElements(), fn ($member) => in_array($member->type(), $types, true)));
                if ($members) {
                    $result[] = ElementGroup::make($members)->label($element->getLabel());
                }
            } else {
                $resolved = is_string($element) ? app($element) : $element;
                if (in_array($resolved->type(), $types, true)) {
                    $result[] = $resolved;
                }
            }
        }

        return $result;
    }

    public static function toolbarGroups(array $elements): array
    {
        $definitions = array_map(fn ($element) => is_string($element) ? app($element) : $element, $elements);

        return ElementGroup::toolbar($definitions, self::metadataForEditor($elements));
    }

    public static function labels(array $elements): array
    {
        return array_map(fn (ElementType $element) => (string) ($element->getLabel() ?? $element->type()), self::all($elements));
    }

    public static function icons(array $elements): array
    {
        return array_map(fn (ElementType $element) => $element->getIcon(), self::all($elements));
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
            $element instanceof ShapeElement => 'shape',
            $element instanceof LineElement => 'line',
            $element instanceof DataTableElement => 'table',
            default => 'element',
        };
    }

    /** Compile each registered type automatically, including custom views. */
    public static function templates(array $elements): array
    {
        return array_map(fn (ElementType $element) => ElementMarkup::template($element), self::all($elements));
    }

    /** The editor derives behavior from class inheritance and media collection declarations. */
    public static function metadataForEditor(array $elements): array
    {
        return array_map(fn (ElementType $element): array => [
            'label' => (string) ($element->getLabel() ?? $element->type()),
            'class' => $element::class,
            'icon' => ($element->getIcon() instanceof \BackedEnum ? $element->getIcon()->value : (string) $element->getIcon()),
            'kind' => self::kind($element),
            'toolbar' => method_exists($element, 'showInToolbar') ? $element->showInToolbar() : true,
            'media_collections' => $element instanceof HasElementMedia ? self::mediaCollectionMetadata($element) : [],
        ], self::all($elements));
    }
}
