<?php

namespace EduardoRibeiroDev\FilamentCanvas\Persistence;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Contracts\DocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Data\SaveResult;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class EloquentDocumentStore implements DocumentStore
{
    /** @param class-string<Model&CanvasOwner> $ownerModel */
    public function __construct(private string $ownerModel, private ?ElementRegistry $elements = null) {}

    public function withElements(ElementRegistry $elements): static
    {
        $store = clone $this;
        $store->elements = $elements;

        return $store;
    }

    public function usingElements(callable $operation): mixed
    {
        return $this->elements === null ? $operation()
            : ElementRegistry::using($this->ownerModel, $this->elements, $operation);
    }

    public function owner(EditorContext $context): Model&CanvasOwner
    {
        $owner = ($this->ownerModel)::query()->findOrFail($context->reference->id);
        if (! $owner instanceof CanvasOwner) {
            throw new InvalidArgumentException('The document model must implement CanvasOwner and may use HasCanvas.');
        }

        return $owner;
    }

    public function authorize(EditorContext $context, string $ability): void
    {
        Gate::forUser($context->user)->authorize($ability === 'view' ? 'view' : 'update', $this->owner($context));
    }

    public function load(EditorContext $context): CanvasDocument
    {
        $owner = $this->owner($context);
        if (method_exists($owner, 'getCanvas')) {
            return $this->usingElements(fn () => $owner->getCanvas($context->user));
        }

        $this->authorize($context, 'view');
        $blocks = $this->usingElements(fn () => app(ElementPersistence::class)->load($owner));
        [$width, $height] = $owner->canvasSize();

        return new CanvasDocument($blocks, $width, $height, ElementPersistence::revision($blocks), $owner->canvasMetadata());
    }

    public function save(EditorContext $context, array $blocks, string $expectedRevision): SaveResult
    {
        $owner = $this->owner($context);
        if (method_exists($owner, 'saveCanvas')) {
            return $this->usingElements(fn () => $owner->saveCanvas($blocks, $expectedRevision, $context->user));
        }

        $this->authorize($context, 'update');
        $saved = $this->usingElements(fn () => app(ElementPersistence::class)->save($this->owner($context), $blocks, $expectedRevision, $context));

        return new SaveResult($saved, ElementPersistence::revision($saved));
    }
}
