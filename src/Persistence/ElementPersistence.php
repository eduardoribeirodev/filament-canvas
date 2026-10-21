<?php

namespace EduardoRibeiroDev\FilamentCanvas\Persistence;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Support\BlockValidator;
use EduardoRibeiroDev\FilamentCanvas\Support\CanvasElements;
use EduardoRibeiroDev\FilamentCanvas\Support\MediaLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ElementPersistence
{
    public function load(Model&CanvasOwner $owner): array
    {
        return $owner->canvasElements()->get()->map(fn ($element) => [
            'type' => $element->type, 'data' => $element->canvasData(),
        ])->all();
    }

    public static function revision(array $blocks): string
    {
        foreach ($blocks as &$block) {
            unset($block['_key'], $block['data']['image_url']);
        }
        unset($block);

        return hash('sha256', json_encode($blocks, JSON_THROW_ON_ERROR));
    }

    public function save(Model&CanvasOwner $owner, array $blocks, ?string $expectedRevision = null, ?EditorContext $context = null): array
    {
        return $owner->getConnection()->transaction(function () use ($owner, $blocks, $expectedRevision, $context): array {
            $owner = $owner->newQuery()->lockForUpdate()->findOrFail($owner->getKey());
            $owner->assertCanvasEditable();
            if ($expectedRevision !== null && $expectedRevision !== self::revision($this->load($owner))) {
                throw ValidationException::withMessages(['blocks' => __('filament-canvas::canvas.stale_document')]);
            }
            [$width, $height] = $owner->canvasSize();
            app(BlockValidator::class)->validate($blocks, new CanvasDocument($blocks, $width, $height), CanvasElements::forOwner($owner));
            Validator::make(['blocks' => $blocks], ['blocks.*.data.id' => ['nullable', 'integer', 'distinct']])->validate();
            if ($context !== null) {
                $context = $context->forDocument($owner);
                (new MediaLibrary($context))->validateReferences($blocks, CanvasElements::forOwner($owner));
            }
            $existing = $owner->canvasElements()->get()->keyBy('id');
            $kept = [];
            foreach ($blocks as $order => $block) {
                $data = $block['data'];
                $id = $data['id'] ?? null;
                if ($id !== null && ! $existing->has($id)) {
                    throw ValidationException::withMessages(['blocks' => __('filament-canvas::canvas.foreign_element')]);
                }
                $element = $id !== null ? $existing[$id] : $owner->canvasElements()->make();
                $element->type = $block['type'];
                $element->fill(Arr::only($data, ['name', 'x_pt', 'y_pt', 'width_pt', 'height_pt', 'rotation_deg', 'visible']));
                $element->layer = $order;
                $element->properties = $element->mergeCanvasData($data['properties']);
                $element->save();
                $element->syncCanvasData($data, $context);
                $kept[] = $element->id;
            }
            // Use model events so domain guards, media, and relation cleanup run.
            foreach ($existing as $element) {
                if (! in_array($element->id, $kept, true)) {
                    $element->delete();
                }
            }
            $owner->unsetRelation('canvasElements');

            return $this->load($owner);
        });
    }
}
