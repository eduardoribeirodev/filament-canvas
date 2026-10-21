<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A host record owns a composition; the package persists its elements. */
interface CanvasOwner
{
    public function canvasElements(): MorphMany;

    /** @return array{0: float, 1: float} Width and height in points. */
    public function canvasSize(): array;

    public function availableCanvasElements(): array;

    public function assertCanvasEditable(): void;

    public function canvasMetadata(): array;
}
