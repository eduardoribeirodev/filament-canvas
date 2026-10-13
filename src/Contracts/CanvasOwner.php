<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A host record owns a composition; the package persists its elements. */
interface CanvasOwner
{
    public function canvasElements(): MorphMany;

    /** @return array{0: float, 1: float} Width and height in points. */
    public function canvasSize(): array;

    public function canvasElementRegistry(): ElementRegistry;

    public function assertCanvasEditable(): void;

    public function canvasMetadata(): array;
}
