<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;

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
}
