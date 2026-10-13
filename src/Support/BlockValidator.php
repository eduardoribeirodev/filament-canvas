<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class BlockValidator
{
    public function validate(array $blocks, CanvasDocument $document, ElementRegistry $elements, ?array $persistedBlocks = null): void
    {
        $blocks = ElementData::canonicalizeBlocks($blocks);
        $rules = [
            'blocks' => ['present', 'array', 'max:300'],
            'blocks.*' => ['required', 'array:type,data'],
            'blocks.*.type' => ['required', Rule::in($elements->types())],
            'blocks.*.data' => ['required', 'array'],
            'blocks.*.data.id' => ['nullable'],
            'blocks.*.data.nome' => ['present', 'string', 'max:255'],
            'blocks.*.data.x_pt' => ['required', 'numeric', 'min:0', 'max:'.$document->width],
            'blocks.*.data.y_pt' => ['required', 'numeric', 'min:0', 'max:'.$document->height],
            'blocks.*.data.largura_pt' => ['required', 'numeric', 'gt:0', 'max:'.$document->width],
            'blocks.*.data.altura_pt' => ['required', 'numeric', 'gt:0', 'max:'.$document->height],
            'blocks.*.data.rotacao_graus' => ['required', 'numeric', 'between:-360,360'],
            'blocks.*.data.visivel' => ['required', 'boolean'],
            'blocks.*.data.dados' => ['present', 'array'],
            'blocks.*.data.imagem_url' => ['nullable', 'string', 'max:4096'],
            'blocks.*.data.imagem_origem' => ['nullable'],
        ];
        $allowed = [];
        $persisted = [];
        foreach ($persistedBlocks ?? $document->blocks as $saved) {
            if (isset($saved['data']['id'])) {
                $persisted[(string) $saved['data']['id']] = $saved;
            }
        }
        foreach ($blocks as $index => $block) {
            if (is_array($block) && in_array($block['type'] ?? null, $elements->types(), true)) {
                $element = $elements->get($block['type']);
                $prefix = 'blocks.'.$index.'.data.dados.';
                $elementRules = ElementValidation::rules($element, is_array($block['data']['dados'] ?? null) ? $block['data']['dados'] : [], $prefix);
                foreach ($elementRules as $property => $propertyRules) {
                    $rules[$property] = $propertyRules;
                }
                $referenceRule = function ($attribute, $value, $fail): void {
                    if ((! is_string($value) && ! is_int($value)) || strlen((string) $value) > 255) {
                        $fail('Referência de mídia inválida.');
                    }
                };
                $rules['blocks.'.$index.'.data.imagem_origem'] = ['nullable', $referenceRule];
                if ($element instanceof HasElementMedia) {
                    foreach ($element->mediaCollections() as $collection) {
                        $path = 'blocks.'.$index.'.data.'.($collection['reference_field'] ?? 'imagem_origem');
                        $maximum = max(1, (int) ($collection['max_files'] ?? 1));
                        if ($maximum === 1) {
                            $rules[$path] = ['nullable', $referenceRule];
                        } else {
                            $rules[$path] = ['nullable', 'array', 'max:'.$maximum];
                            $rules[$path.'.*'] = ['required', $referenceRule];
                        }
                    }
                }
                $allowed[$index] = array_unique(array_map(fn ($key) => explode('.', substr($key, strlen($prefix)))[0], array_keys($elementRules)));
            }
        }
        $validator = Validator::make(['blocks' => $blocks], $rules);
        $validator->after(function ($validator) use ($blocks, $document, $allowed, $persisted): void {
            foreach ($blocks as $index => $block) {
                if (! is_array($block['data'] ?? null)) {
                    continue;
                }
                $data = $block['data'];
                if (is_array($data['dados'] ?? null) && isset($allowed[$index])) {
                    foreach (array_diff(array_keys($data['dados']), $allowed[$index]) as $property) {
                        $saved = $persisted[(string) ($data['id'] ?? '')] ?? null;
                        // Imported properties can remain unchanged; new undeclared input is rejected.
                        if ($saved && $saved['type'] === $block['type'] && array_key_exists($property, $saved['data']['dados'] ?? [])
                            && $saved['data']['dados'][$property] === $data['dados'][$property]) {
                            continue;
                        }
                        $validator->errors()->add('blocks.'.$index.'.data.dados.'.$property, 'Propriedade não permitida para este tipo de elemento.');
                    }
                }
                foreach (['x_pt' => ['largura_pt', $document->width], 'y_pt' => ['altura_pt', $document->height]] as $coordinate => [$size, $limit]) {
                    if (is_numeric($data[$coordinate] ?? null) && is_numeric($data[$size] ?? null) && (float) $data[$coordinate] + (float) $data[$size] > $limit + .001) {
                        $validator->errors()->add('blocks.'.$index.'.data.'.$coordinate, 'Elemento fora dos limites da prancheta.');
                    }
                }
            }
        });
        $validator->validate();
    }
}
