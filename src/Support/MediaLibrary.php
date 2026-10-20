<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Models\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Rendering\Html;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/** Direct Spatie integration; all element-specific settings live in mediaCollections(). */
class MediaLibrary
{
    public function __construct(public readonly EditorContext $context) {}

    public function query(HasElementMedia $element, string $collection): Builder
    {
        $settings = $element->mediaCollections()[$collection]
            ?? throw ValidationException::withMessages(['media' => __('filament-canvas::canvas.unknown_collection')]);
        $model = config('media-library.media_model', Media::class);
        $query = $model::query();
        $owner = $this->context->document;
        if ($owner !== null) {
            Gate::forUser($this->context->user)->authorize('view', $owner);
        }
        if (isset($settings['query'])) {
            $query->where(fn (Builder $scope) => $settings['query']($scope, $this->context));
        } elseif ($owner instanceof CanvasOwner) {
            $elements = $owner->canvasElements();
            $query->where(fn (Builder $scope) => $scope
                ->where(fn (Builder $direct) => $direct->where('model_type', $owner->getMorphClass())->where('model_id', $owner->getKey()))
                ->orWhere(fn (Builder $nested) => $nested->where('model_type', $elements->getRelated()->getMorphClass())
                    ->whereIn('model_id', $elements->select($elements->getRelated()->qualifyColumn('id')))));
        } else {
            $query->whereRaw('1 = 0');
        }
        $accepted = $settings['accepted_mime_types'] ?? ['image/png', 'image/jpeg', 'image/webp'];

        return $query->where(function (Builder $mime) use ($accepted): void {
            foreach ($accepted as $type) {
                str_ends_with($type, '/*')
                    ? $mime->orWhere('mime_type', 'like', substr($type, 0, -1).'%')
                    : $mime->orWhere('mime_type', $type);
            }
            if ($accepted === []) {
                $mime->whereRaw('1 = 0');
            }
        });
    }

    private function canUse(Media $media, array $settings): bool
    {
        $owner = $media->model;
        $owner = $owner instanceof CanvasElement ? $owner->canvasOwner() : $owner;

        return $owner instanceof Model && Gate::forUser($this->context->user)->allows('view', $owner)
            && (! isset($settings['authorize']) || $settings['authorize']($media, $this->context));
    }

    public function assets(HasElementMedia $element, ?string $collection = null): array
    {
        if ($collection === null) {
            $assets = [];
            foreach (array_keys($element->mediaCollections()) as $name) {
                foreach ($this->assets($element, $name) as $asset) {
                    $assets[$asset['id']] = $asset;
                }
            }

            return array_values($assets);
        }
        if ($this->context->document === null && ! isset($element->mediaCollections()[$collection]['query'])) {
            return [];
        }

        return $this->query($element, $collection)->with('model')->orderBy('name')->get()
            ->filter(fn (Media $media) => $this->canUse($media, $element->mediaCollections()[$collection]))
            ->map(fn (Media $media) => $this->describe($media))->values()->all();
    }

    public function find(int|string $id, HasElementMedia $element, string $collection): ?Media
    {
        if ($this->context->document === null && ! isset($element->mediaCollections()[$collection]['query'])) {
            return null;
        }
        $media = $this->query($element, $collection)->find($id);

        return $media && $this->canUse($media, $element->mediaCollections()[$collection]) ? $media : null;
    }

    public function describe(Media $media): array
    {
        $url = MediaFiles::url($media, $this->context);

        return ['id' => $media->getKey(), 'name' => $media->name, 'url' => $url, 'image' => $url, 'mime_type' => $media->mime_type];
    }

    public function resolve(HasElementMedia $element, array $block): array
    {
        $result = [];
        foreach ($element->mediaCollections() as $name => $settings) {
            $reference = data_get($block['data'] ?? [], $settings['reference_field'] ?? 'image_id');
            $multiple = ($settings['max_files'] ?? 1) > 1;
            $ids = $multiple ? (is_array($reference) ? $reference : []) : [$reference];
            $resolved = [];
            foreach ($ids as $id) {
                if ((is_string($id) || is_int($id)) && ($media = $this->find($id, $element, $name))) {
                    $resolved[] = $this->describe($media);
                }
            }
            $result[$name] = $multiple ? $resolved : ($resolved[0] ?? ['id' => $reference, 'image' => '', 'name' => '']);
            if ($name === 'image' && ! $multiple && $resolved === [] && ! $reference) {
                $result[$name]['image'] = Html::rawImageUrl($block['data']['image_url'] ?? $block['data']['properties']['image_url'] ?? '');
            }
        }

        return $result;
    }

    public function validateReferences(array $blocks, ElementRegistry $elements): void
    {
        foreach ($blocks as $index => $block) {
            $element = $elements->get($block['type']);
            if (! $element instanceof HasElementMedia) {
                continue;
            }
            foreach ($element->mediaCollections() as $name => $settings) {
                $field = $settings['reference_field'] ?? 'image_id';
                $reference = data_get($block['data'], $field);
                $ids = ($settings['max_files'] ?? 1) > 1 ? ($reference ?? []) : ($reference ? [$reference] : []);
                $error = ['blocks.'.$index.'.data.'.$field => __('filament-canvas::canvas.media_unavailable')];
                if (! is_array($ids) || count($ids) > max(1, (int) ($settings['max_files'] ?? 1))) {
                    throw ValidationException::withMessages($error);
                }
                foreach ($ids as $id) {
                    if ((! is_string($id) && ! is_int($id)) || ! $this->find($id, $element, $name)) {
                        throw ValidationException::withMessages($error);
                    }
                }
            }
        }
    }

    public function upload(UploadedFile $file, HasElementMedia $element, string $collection): array
    {
        $settings = $element->mediaCollections()[$collection]
            ?? throw ValidationException::withMessages(['file' => __('filament-canvas::canvas.unknown_collection')]);
        $document = $this->context->document;
        $owner = isset($settings['upload_owner']) ? $settings['upload_owner']($this->context) : $document;
        if (! $owner instanceof Model || ! $owner instanceof HasMedia) {
            throw ValidationException::withMessages(['file' => 'Configure upload_owner for this collection.']);
        }
        Gate::forUser($this->context->user)->authorize('update', $owner);
        $accepted = $settings['accepted_mime_types'] ?? ['image/png', 'image/jpeg', 'image/webp'];
        $rules = ['required', 'file', 'mimetypes:'.implode(',', $accepted), 'max:'.($settings['max_size'] ?? 10240)];
        if ($accepted !== [] && collect($accepted)->every(fn ($mime) => in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true))) {
            $rules[] = 'image';
        }
        Validator::make(['file' => $file], ['file' => $rules])->validate();
        $created = [];
        try {
            return $owner->getConnection()->transaction(function () use ($owner, $document, $file, $settings, $element, $collection, &$created): array {
                $owner->newQuery()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
                if ($document instanceof CanvasOwner) {
                    $document->assertCanvasEditable();
                }
                $before = $owner->media()->pluck('id')->all();
                try {
                    $name = pathinfo(basename(str_replace('\\', '/', $file->getClientOriginalName())), PATHINFO_FILENAME);
                    $name = Str::limit(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: __('filament-canvas::canvas.media'), 255, '');
                    $media = $owner->addMedia($file)->preservingOriginal()->usingName($name)
                        ->usingFileName(Str::uuid().'.'.($file->guessExtension() ?: 'bin'))
                        ->withCustomProperties(['sha256' => hash_file('sha256', $file->getRealPath())])
                        ->toMediaCollection($settings['upload_collection'] ?? config('filament-canvas.library_collection', 'library'),
                            $settings['disk'] ?? config('filament-canvas.media_disk', 'public'));
                    if (! $this->find($media->getKey(), $element, $collection)) {
                        throw ValidationException::withMessages(['file' => __('filament-canvas::canvas.upload_unavailable')]);
                    }

                    return $this->describe($media);
                } catch (Throwable $exception) {
                    $created = $owner->media()->whereNotIn('id', $before)->get()->all();
                    throw $exception;
                }
            });
        } catch (Throwable $exception) {
            foreach ($created as $media) {
                app(Filesystem::class)->removeAllFiles($media);
            }
            throw $exception;
        }
    }
}
