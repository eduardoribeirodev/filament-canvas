<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use InvalidArgumentException;

/** Presentation-only grouping; it never becomes a saved element type. */
final class ElementGroup
{
    private ?string $label = null;

    /** @param array<ElementType|class-string<ElementType>> $elements */
    private function __construct(private array $elements) {}

    public static function make(array $elements): self
    {
        return new self($elements);
    }

    public function label(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getElements(): array
    {
        return $this->elements;
    }

    /** Resolve group members without registering a new saved type. */
    public function resolveElements(): array
    {
        return array_map(function ($element): ElementType {
            if ($element instanceof self) {
                throw new InvalidArgumentException('Canvas toolbar groups cannot be nested.');
            }
            $element = is_string($element) ? app($element) : $element;
            if (! $element instanceof ElementType) {
                throw new InvalidArgumentException('Canvas elements must implement ElementType.');
            }

            return $element;
        }, $this->elements);
    }

    /** Build toolbar sections from the owner configuration and allowed types. */
    public static function toolbar(array $definitions, array $metadata): array
    {
        $seen = [];
        $groups = [];
        foreach ($definitions as $definition) {
            $grouped = $definition instanceof self;
            $members = $grouped ? $definition->resolveElements() : [$definition];
            $types = [];
            foreach ($members as $element) {
                $type = $element->type();
                if (isset($seen[$type]) || ! isset($metadata[$type]) || ! $metadata[$type]['toolbar']) {
                    continue;
                }
                $seen[$type] = true;
                $types[] = $type;
            }
            if ($types !== []) {
                $groups[] = ['label' => $grouped ? $definition->getLabel() : null, 'types' => $types, 'grouped' => $grouped];
            }
        }

        return $groups;
    }
}
