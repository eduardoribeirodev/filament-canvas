<?php

namespace EduardoRibeiroDev\FilamentCanvas\Models\Concerns;

use EduardoRibeiroDev\FilamentCanvas\Models\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Implement CanvasOwner and use this trait to get the default persistence. */
trait HasCanvas
{
    public function canvasElements(): MorphMany
    {
        return $this->morphMany($this->canvasElementModel(), 'elementable')->orderBy('camada')->orderBy('id');
    }

    public function canvasElementModel(): string
    {
        return CanvasElement::class;
    }

    public function canvasSize(): array
    {
        return [(float) config('filament-canvas.width', 595.28), (float) config('filament-canvas.height', 841.89)];
    }

    public function canvasElementRegistry(): ElementRegistry
    {
        return ElementRegistry::configured();
    }

    public function assertCanvasEditable(): void {}

    public function canvasMetadata(): array
    {
        return [];
    }

    public static function bootHasCanvas(): void
    {
        static::deleting(function ($owner): void {
            $owner->assertCanvasEditable();
            // A polymorphic owner cannot have a database cascade foreign key.
            $owner->canvasElements()->get()->each(fn ($element) => $element->delete());
        });
    }
}
