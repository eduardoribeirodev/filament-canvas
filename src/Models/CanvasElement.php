<?php

namespace EduardoRibeiroDev\FilamentCanvas\Models;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementValidation;
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

    protected $attributes = ['rotacao_graus' => 0, 'camada' => 0, 'visivel' => true];

    // Collections depend on the persisted element type and its owner's registry.
    public bool $registerMediaConversionsUsingModelInstance = true;

    /** Input compatibility for old imports; only dados is written to the database. */
    private array $legacyElementInput = [];

    protected function casts(): array
    {
        return ['dados' => 'array', 'visivel' => 'boolean'];
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
            throw ValidationException::withMessages(['elementable_id' => 'O proprietário deve implementar CanvasOwner.']);
        }

        return $owner;
    }

    public function getDadosAttribute($value): array
    {
        $decode = fn ($json) => is_array($json) ? $json : (json_decode($json ?? 'null', true) ?? []);
        $data = $value !== null
            ? ['dados' => $decode($value)]
            : array_map($decode, array_intersect_key($this->attributes, array_flip(['conteudo', 'estilo', 'configuracoes'])));
        $dados = ElementData::from($data);

        return array_replace($dados, ElementData::from($this->legacyElementInput));
    }

    public function setDadosAttribute($value): void
    {
        $this->attributes['dados'] = json_encode($value ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->legacyElementInput = [];
    }

    public function getConteudoAttribute($value): array
    {
        return array_intersect_key($this->dados, array_flip(['text', 'rich_text', 'html', 'field', 'rows', 'imagem_path']));
    }

    public function setConteudoAttribute($value): void
    {
        if (is_array($value) && array_key_exists('text', $value) && ! array_key_exists('html', $value) && ! array_key_exists('rich_text', $value)) {
            $dados = $this->dados;
            if ($value['text'] !== ($dados['text'] ?? null)) {
                unset($dados['html'], $dados['rich_text']);
                $this->setDadosAttribute(array_replace($dados, $value));

                return;
            }
        }
        $this->legacyElementInput['conteudo'] = $value ?? [];
    }

    public function getEstiloAttribute($value): array
    {
        return array_intersect_key($this->dados, array_flip(['font_size', 'color', 'align', 'font', 'bold', 'italic', 'leading', 'background']));
    }

    public function setEstiloAttribute($value): void
    {
        $this->legacyElementInput['estilo'] = $value ?? [];
    }

    public function getConfiguracoesAttribute($value): array
    {
        return $this->dados;
    }

    public function setConfiguracoesAttribute($value): void
    {
        $this->legacyElementInput['configuracoes'] = $value ?? [];
    }

    public function canvasData(): array
    {
        $data = $this->only(['id', 'nome', 'x_pt', 'y_pt', 'largura_pt', 'altura_pt', 'rotacao_graus', 'visivel', 'dados']);
        $definition = ElementRegistry::forOwner($this->canvasOwner())->get($this->tipo);
        if ($definition instanceof HasElementMedia) {
            foreach ($definition->mediaCollections() as $name => $collection) {
                $multiple = ($collection['max_files'] ?? 1) > 1;
                $items = $this->getMedia($name === 'image' ? 'imagem' : $name);
                data_set($data, $collection['reference_field'] ?? 'imagem_origem', $multiple ? $items->modelKeys() : $items->first()?->id);
            }
        }
        $image = $definition instanceof HasElementMedia ? $this->imagem() : null;
        $data['imagem_origem'] ??= $image?->id;
        $data['imagem_url'] = $image?->getUrl() ?? ($this->dados['image_url'] ?? null);

        return $data;
    }

    /** Preserve unexposed properties while accepting deliberate rich/plain text changes. */
    public function mergeCanvasData(array $incoming): array
    {
        $dados = array_replace($this->dados, $incoming);
        $definition = ElementRegistry::forOwner($this->canvasOwner())->get($this->tipo);
        if ($definition instanceof TextElement) {
            if (array_key_exists('html', $incoming) && ! array_key_exists('rich_text', $incoming)) {
                unset($dados['rich_text']);
            } elseif (! array_key_exists('rich_text', $incoming) && ! array_key_exists('html', $incoming)
                && array_key_exists('text', $incoming) && $incoming['text'] !== ($this->dados['text'] ?? '')) {
                unset($dados['rich_text'], $dados['html']);
            }
        }

        return $definition->normalize($dados);
    }

    /** Optional domain subclasses can persist their own relations in the same transaction. */
    public function syncCanvasData(array $data, ?EditorContext $context = null): void
    {
        $definition = ElementRegistry::forOwner($this->canvasOwner())->get($this->tipo);
        if (! $definition instanceof HasElementMedia) {
            return;
        }
        foreach ($definition->mediaCollections() as $name => $collection) {
            $field = $collection['reference_field'] ?? 'imagem_origem';
            if (! Arr::has($data, $field)) {
                continue;
            }
            $collectionName = $name === 'image' ? 'imagem' : $name;
            $reference = data_get($data, $field);
            $ids = ($collection['max_files'] ?? 1) > 1 ? ($reference ?? []) : ($reference ? [$reference] : []);
            $current = $this->getMedia($collectionName);
            if (array_map('strval', $current->modelKeys()) === array_map('strval', $ids)) {
                continue;
            }
            $sources = Media::query()->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ids as $id) {
                $source = $sources->get($id);
                $sourceOwner = $source?->model;
                $sourceOwner = $sourceOwner instanceof self ? $sourceOwner->elementable : $sourceOwner;
                if (! $source || ! $sourceOwner instanceof Model || ! $sourceOwner->is($this->canvasOwner())) {
                    throw ValidationException::withMessages([$field => 'A imagem não pertence a este documento.']);
                }
            }
            foreach ($current as $media) {
                if (! in_array((string) $media->id, array_map('strval', $ids), true)) {
                    $media->delete();
                }
            }
            foreach ($ids as $position => $id) {
                $media = $current->firstWhere('id', $id) ?? $sources[$id]->copy($this, $collectionName, config('filament-canvas.media_disk', 'public'));
                $media->order_column = $position + 1;
                $media->save();
            }
            $this->unsetRelation('media');
        }
    }

    public function registerMediaCollections(): void
    {
        $definition = ElementRegistry::forOwner($this->canvasOwner())->get($this->tipo);
        if (! $definition instanceof HasElementMedia) {
            return;
        }
        foreach ($definition->mediaCollections() as $name => $collection) {
            $definition = $this->addMediaCollection($name === 'image' ? 'imagem' : $name)
                ->useDisk(config('filament-canvas.media_disk', 'public'))
                ->acceptsMimeTypes($collection['accepted_mime_types'] ?? ['image/png', 'image/jpeg', 'image/webp']);
            if (($collection['max_files'] ?? 1) === 1) {
                $definition->singleFile();
            }
        }
    }

    public function imagem(): ?Media
    {
        return $this->getFirstMedia('imagem');
    }

    protected function validateConfigurations(): void
    {
        $definition = ElementRegistry::forOwner($this->canvasOwner())->get($this->tipo);
        $this->dados = $definition->normalize($this->dados);
        ElementValidation::validate($definition, $this->dados);
        unset($this->attributes['conteudo'], $this->attributes['estilo'], $this->attributes['configuracoes']);
    }

    protected static function booted(): void
    {
        static::saving(function (self $element): void {
            $owner = $element->canvasOwner();
            $owner->assertCanvasEditable();
            if ($element->exists && ($element->isDirty('elementable_id') || $element->isDirty('elementable_type'))) {
                throw ValidationException::withMessages(['elementable_id' => 'Duplique o elemento para mudar seu proprietário.']);
            }
            [$width, $height] = $owner->canvasSize();
            Validator::make($element->only(['nome', 'tipo', 'x_pt', 'y_pt', 'largura_pt', 'altura_pt', 'rotacao_graus']), [
                'nome' => ['present', 'string', 'max:255'], 'tipo' => ['required', Rule::in(ElementRegistry::forOwner($owner)->types())],
                'x_pt' => ['required', 'numeric', 'min:0'], 'y_pt' => ['required', 'numeric', 'min:0'],
                'largura_pt' => ['required', 'numeric', 'gt:0'], 'altura_pt' => ['required', 'numeric', 'gt:0'],
                'rotacao_graus' => ['nullable', 'numeric', 'between:-360,360'],
            ])->validate();
            if ($element->x_pt + $element->largura_pt > $width + .001 || $element->y_pt + $element->altura_pt > $height + .001) {
                throw ValidationException::withMessages(['x_pt' => 'Elemento fora dos limites da prancheta.']);
            }
            $element->validateConfigurations();
        });
    }
}
