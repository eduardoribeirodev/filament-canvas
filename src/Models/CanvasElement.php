<?php

namespace EduardoRibeiroDev\FilamentCanvas\Models;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Support\CanvasElements;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementValidation;
use EduardoRibeiroDev\FilamentCanvas\Support\MediaFiles;
use EduardoRibeiroDev\FilamentCanvas\Support\MediaLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CanvasElement extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'canvas_elements';

    protected $guarded = ['id', 'elementable_type', 'elementable_id'];

    protected $attributes = ['rotation_deg' => 0, 'layer' => 0, 'visible' => true];

    // Collections depend on the persisted element type and its owner's registry.
    public bool $registerMediaConversionsUsingModelInstance = true;

    protected function casts(): array
    {
        return ['properties' => 'array', 'visible' => 'boolean'];
    }

    public function elementable(): MorphTo
    {
        return $this->morphTo();
    }

    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return $this->getConnection()->transaction(function () {
            $this->canvasOwner()->assertCanvasEditable();

            return parent::delete();
        });
    }

    public function canvasOwner(): Model&CanvasOwner
    {
        $owner = $this->elementable;
        if (! $owner instanceof Model || ! $owner instanceof CanvasOwner) {
            throw ValidationException::withMessages(['elementable_id' => 'The owner must implement CanvasOwner.']);
        }

        return $owner;
    }

    public function canvasData(): array
    {
        $data = $this->only(['id', 'name', 'x_pt', 'y_pt', 'width_pt', 'height_pt', 'rotation_deg', 'visible', 'properties']);
        $definition = CanvasElements::resolve(CanvasElements::forOwner($this->canvasOwner()), $this->type);
        if ($definition instanceof HasElementMedia) {
            foreach ($definition->mediaCollections() as $name => $collection) {
                $multiple = ($collection['max_files'] ?? 1) > 1;
                $items = $this->getMedia($name === 'image' ? 'image' : $name);
                data_set($data, $collection['reference_field'] ?? 'image_id', $multiple ? $items->modelKeys() : $items->first()?->id);
            }
        }
        $image = $definition instanceof HasElementMedia ? $this->image() : null;
        $data['image_id'] ??= $image?->id;
        $data['image_url'] = MediaFiles::url($image) ?? ($this->properties['image_url'] ?? null);

        return $data;
    }

    public function mergeCanvasData(array $incoming): array
    {
        $definition = CanvasElements::resolve(CanvasElements::forOwner($this->canvasOwner()), $this->type);

        return ElementData::normalize($definition, $incoming);
    }

    /** Optional domain subclasses can persist their own relations in the same transaction. */
    public function syncCanvasData(array $data, ?EditorContext $context = null): void
    {
        $definition = CanvasElements::resolve(CanvasElements::forOwner($this->canvasOwner()), $this->type);
        if (! $definition instanceof HasElementMedia) {
            return;
        }
        foreach ($definition->mediaCollections() as $name => $collection) {
            $field = $collection['reference_field'] ?? 'image_id';
            if (! Arr::has($data, $field)) {
                continue;
            }
            $collectionName = $name === 'image' ? 'image' : $name;
            $reference = data_get($data, $field);
            $ids = ($collection['max_files'] ?? 1) > 1 ? ($reference ?? []) : ($reference ? [$reference] : []);
            $current = $this->getMedia($collectionName);
            $model = config('media-library.media_model', Media::class);
            $sources = $model::query()->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ids as $id) {
                $source = $sources->get($id);
                $sourceOwner = $source?->model;
                $sourceOwner = $sourceOwner instanceof self ? $sourceOwner->elementable : $sourceOwner;
                $authorized = $context !== null
                    ? (new MediaLibrary($context->forDocument($this->canvasOwner())))->find($id, $definition, $name) !== null
                    : ($sourceOwner instanceof Model && $sourceOwner->is($this->canvasOwner()));
                if (! $source || ! $authorized) {
                    throw ValidationException::withMessages([$field => __('filament-canvas::canvas.media_unavailable')]);
                }
            }
            if (array_map('strval', $current->modelKeys()) === array_map('strval', $ids)) {
                continue;
            }
            foreach ($current as $media) {
                if (! in_array((string) $media->id, array_map('strval', $ids), true)) {
                    $media->delete();
                }
            }
            foreach ($ids as $position => $id) {
                $media = $current->firstWhere('id', $id) ?? $sources[$id]->copy($this, $collectionName, $collection['disk'] ?? config('filament-canvas.media_disk', 'public'));
                $media->order_column = $position + 1;
                $media->save();
            }
            $this->unsetRelation('media');
        }
    }

    public function registerMediaCollections(): void
    {
        $definition = CanvasElements::resolve(CanvasElements::forOwner($this->canvasOwner()), $this->type);
        if (! $definition instanceof HasElementMedia) {
            return;
        }
        foreach ($definition->mediaCollections() as $name => $collection) {
            $definition = $this->addMediaCollection($name === 'image' ? 'image' : $name)
                ->useDisk($collection['disk'] ?? config('filament-canvas.media_disk', 'public'))
                ->acceptsMimeTypes($collection['accepted_mime_types'] ?? ['image/png', 'image/jpeg', 'image/webp']);
            if (($collection['max_files'] ?? 1) === 1) {
                $definition->singleFile();
            }
        }
    }

    public function image(): ?Media
    {
        return $this->getFirstMedia('image');
    }

    protected function validateConfigurations(): void
    {
        $definition = CanvasElements::resolve(CanvasElements::forOwner($this->canvasOwner()), $this->type);
        $this->properties = ElementData::normalize($definition, $this->properties);
        ElementValidation::validate($definition, $this->properties);
    }

    protected static function booted(): void
    {
        static::saving(function (self $element): void {
            $owner = $element->canvasOwner();
            $owner->assertCanvasEditable();
            if ($element->exists && ($element->isDirty('elementable_id') || $element->isDirty('elementable_type'))) {
                throw ValidationException::withMessages(['elementable_id' => __('filament-canvas::canvas.change_owner_error')]);
            }
            [$width, $height] = $owner->canvasSize();
            Validator::make($element->only(['name', 'type', 'x_pt', 'y_pt', 'width_pt', 'height_pt', 'rotation_deg']), [
                'name' => ['present', 'string', 'max:255'], 'type' => ['required', Rule::in(CanvasElements::types(CanvasElements::forOwner($owner)))],
                'x_pt' => ['required', 'numeric', 'min:0'], 'y_pt' => ['required', 'numeric', 'min:0'],
                'width_pt' => ['required', 'numeric', 'gt:0'], 'height_pt' => ['required', 'numeric', 'gt:0'],
                'rotation_deg' => ['nullable', 'numeric', 'between:-360,360'],
            ])->validate();
            if ($element->x_pt + $element->width_pt > $width + .001 || $element->y_pt + $element->height_pt > $height + .001) {
                throw ValidationException::withMessages(['x_pt' => __('filament-canvas::canvas.bounds_error')]);
            }
            $element->validateConfigurations();
        });
    }
}
