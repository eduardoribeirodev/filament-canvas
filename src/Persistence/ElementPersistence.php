<?php

namespace EduardoRibeiroDev\FilamentCanvas\Persistence;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Support\BlockValidator;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ElementPersistence
{
    public function load(Model&CanvasOwner $owner): array
    {
        return $owner->canvasElements()->get()->map(fn ($element) => [
            'type' => $element->tipo, 'data' => $element->canvasData(),
        ])->all();
    }

    public static function revision(array $blocks): string
    {
        foreach ($blocks = ElementData::canonicalizeBlocks($blocks) as &$block) {
            unset($block['_key'], $block['data']['imagem_url']);
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
                throw ValidationException::withMessages(['blocks' => 'Esta página foi alterada em outra janela. Recarregue antes de continuar.']);
            }
            $blocks = ElementData::canonicalizeBlocks($blocks);
            [$width, $height] = $owner->canvasSize();
            // Legacy imports omit envelope defaults, but every persisted block is canonical.
            foreach ($blocks as &$block) {
                $block['data'] += ['rotacao_graus' => 0, 'visivel' => true, 'dados' => []];
            }
            unset($block);
            app(BlockValidator::class)->validate($blocks, new CanvasDocument($blocks, $width, $height), ElementRegistry::forOwner($owner), $this->load($owner));
            Validator::make(['blocks' => $blocks], ['blocks.*.data.id' => ['nullable', 'integer', 'distinct']])->validate();
            $existing = $owner->canvasElements()->get()->keyBy('id');
            $kept = [];
            foreach ($blocks as $order => $block) {
                $data = $block['data'];
                $id = $data['id'] ?? null;
                if ($id !== null && ! $existing->has($id)) {
                    throw ValidationException::withMessages(['blocks' => 'O elemento não pertence a este documento.']);
                }
                $element = $id !== null ? $existing[$id] : $owner->canvasElements()->make();
                $element->tipo = $block['type'];
                $element->fill(Arr::only($data, ['nome', 'x_pt', 'y_pt', 'largura_pt', 'altura_pt', 'rotacao_graus', 'visivel']));
                $element->camada = $order;
                $element->dados = $element->mergeCanvasData($data['dados']);
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
