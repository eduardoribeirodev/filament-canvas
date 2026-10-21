<?php

namespace EduardoRibeiroDev\FilamentCanvas\Models\Concerns;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Data\SaveResult;
use EduardoRibeiroDev\FilamentCanvas\Models\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Persistence\ElementPersistence;
use EduardoRibeiroDev\FilamentCanvas\Rendering\CanvasRenderer;
use EduardoRibeiroDev\FilamentCanvas\Support\CanvasElements;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Implement CanvasOwner and use this trait to get the default persistence. */
trait HasCanvas
{
    /** Load the saved composition after checking view access. */
    public function getCanvas(?Authenticatable $user = null): CanvasDocument
    {
        $this->assertCanvasPersisted();
        Gate::forUser($this->canvasUser($user))->authorize('view', $this);

        $blocks = app(ElementPersistence::class)->load($this);
        [$width, $height] = $this->canvasSize();

        return new CanvasDocument(
            $blocks,
            $width,
            $height,
            ElementPersistence::revision($blocks),
            $this->canvasMetadata(),
        );
    }

    /** Persist a composition atomically, rejecting a stale expected revision. */
    public function saveCanvas(
        array $blocks,
        string $expectedRevision,
        ?Authenticatable $user = null,
    ): SaveResult {
        $this->assertCanvasPersisted();
        $user = $this->canvasUser($user);
        Gate::forUser($user)->authorize('update', $this);

        $elements = CanvasElements::forOwner($this);
        foreach ($blocks as &$block) {
            if (
                is_array($block)
                && in_array($block['type'] ?? null, CanvasElements::types($elements), true)
                && is_array($block['data']['properties'] ?? null)
            ) {
                $block['data']['properties'] = ElementData::normalize(
                    CanvasElements::resolve($elements, $block['type']),
                    $block['data']['properties'],
                );
            }
        }
        unset($block);

        $context = new EditorContext(
            new DocumentReference(static::class, $this->getKey()),
            $user,
            Filament::getCurrentPanel()?->getId(),
            $this,
        );
        $saved = app(ElementPersistence::class)->save($this, $blocks, $expectedRevision, $context);

        $this->unsetRelation('canvasElements');

        return new SaveResult($saved, ElementPersistence::revision($saved));
    }

    /** Render the saved canvas as standalone HTML using the current user's permissions. */
    public function renderCanvas(?string $template = null, ?Authenticatable $user = null): string
    {
        $document = $this->getCanvas($user);
        $user = $this->canvasUser($user);
        $panel = Filament::getCurrentPanel();
        $plugin = $panel?->hasPlugin('filament-canvas')
            ? $panel->getPlugin('filament-canvas')
            : CanvasPlugin::make();
        $type = static::class;
        $plugin = $plugin->forModel($type, $type, $template === null ? [] : ['template' => $template]);
        $renderer = new CanvasRenderer($plugin->elements($type), $plugin->getTemplate());

        return $renderer->renderOwner($this, $document, $user, $panel?->getId());
    }

    /** Null inherits the panel or package template. */
    public function canvasTemplate(): ?string
    {
        return null;
    }

    /** Null inherits the package's configured element types. */
    public function canvasElementsAvailable(): ?array
    {
        return null;
    }

    private function canvasUser(?Authenticatable $user): ?Authenticatable
    {
        $panel = Filament::getCurrentPanel();

        return $user ?? ($panel ? auth($panel->getAuthGuard())->user() : auth()->user());
    }

    private function assertCanvasPersisted(): void
    {
        if (! $this->exists || $this->getKey() === null) {
            throw new LogicException('Save the model before rendering its canvas.');
        }
    }

    public function canvasElements(): MorphMany
    {
        return $this->morphMany($this->canvasElementModel(), 'elementable')->orderBy('layer')->orderBy('id');
    }

    public function canvasElementModel(): string
    {
        return CanvasElement::class;
    }

    public function canvasSize(): array
    {
        return [(float) config('filament-canvas.width', 595.28), (float) config('filament-canvas.height', 841.89)];
    }

    public function availableCanvasElements(): array
    {
        $elements = $this->canvasElementsAvailable();

        return $elements === null ? CanvasElements::configured() : CanvasElements::list($elements);
    }

    public function resolveCanvasElement(string $type): ElementType
    {
        return CanvasElements::resolve(CanvasElements::forOwner($this), $type);
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
