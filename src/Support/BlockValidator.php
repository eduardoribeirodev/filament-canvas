<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BlockValidator
{
    public function validate(array $blocks, CanvasDocument $document, array $elements): void
    {
        $rules = [
            'blocks' => ['present', 'array', 'max:300'],
            'blocks.*' => ['required', 'array:type,data'],
            'blocks.*.type' => ['required', Rule::in(CanvasElements::types($elements))],
            'blocks.*.data' => ['required', 'array'],
            'blocks.*.data.id' => ['nullable'],
            'blocks.*.data.name' => ['present', 'string', 'max:255'],
            'blocks.*.data.x_pt' => ['required', 'numeric', 'min:0', 'max:'.$document->width],
            'blocks.*.data.y_pt' => ['required', 'numeric', 'min:0', 'max:'.$document->height],
            'blocks.*.data.width_pt' => ['required', 'numeric', 'gt:0', 'max:'.$document->width],
            'blocks.*.data.height_pt' => ['required', 'numeric', 'gt:0', 'max:'.$document->height],
            'blocks.*.data.rotation_deg' => ['required', 'numeric', 'between:-360,360'],
            'blocks.*.data.visible' => ['required', 'boolean'],
            'blocks.*.data.properties' => ['present', 'array'],
            'blocks.*.data.image_url' => ['nullable', 'string', 'max:4096'],
            'blocks.*.data.image_id' => ['nullable'],
        ];
        $elementErrors = [];
        $elementFailures = [];
        foreach ($blocks as $index => $block) {
            if (is_array($block) && in_array($block['type'] ?? null, CanvasElements::types($elements), true)) {
                $element = CanvasElements::resolve($elements, $block['type']);
                $envelope = ['id', 'name', 'x_pt', 'y_pt', 'width_pt', 'height_pt', 'rotation_deg', 'visible', 'properties', 'image_id', 'image_url', 'references'];
                $prefix = 'blocks.'.$index.'.data.properties.';
                $properties = is_array($block['data']['properties'] ?? null) ? $block['data']['properties'] : [];
                try {
                    // Relative rules and field dependencies are scoped to this element.
                    $elementRules = ElementValidation::rules($element, $properties);
                    Validator::make($properties, $elementRules)->validate();
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $path => $messages) {
                        $elementErrors[$prefix.$path] = $messages;
                        $elementFailures[$prefix.$path] = $exception->validator->failed()[$path] ?? [];
                    }
                }
                $referenceRule = function ($attribute, $value, $fail): void {
                    if ((! is_string($value) && ! is_int($value)) || strlen((string) $value) > 255) {
                        $fail('Invalid media reference.');
                    }
                };
                $rules['blocks.'.$index.'.data.image_id'] = ['nullable', $referenceRule];
                if ($element instanceof HasElementMedia) {
                    foreach ($element->mediaCollections() as $collection) {
                        $field = $collection['reference_field'] ?? 'image_id';
                        $envelope[] = explode('.', $field)[0];
                        $path = 'blocks.'.$index.'.data.'.$field;
                        $maximum = max(1, (int) ($collection['max_files'] ?? 1));
                        if ($maximum === 1) {
                            $rules[$path] = ['nullable', $referenceRule];
                        } else {
                            $rules[$path] = ['nullable', 'array', 'max:'.$maximum];
                            $rules[$path.'.*'] = ['required', $referenceRule];
                        }
                    }
                }
                $rules['blocks.'.$index.'.data'] = ['required', 'array:'.implode(',', array_unique($envelope))];
            }
        }
        $validator = Validator::make(['blocks' => $blocks], $rules);
        $validator->after(function ($validator) use ($blocks, $document, $elementErrors, $elementFailures): void {
            foreach ($elementErrors as $path => $messages) {
                // Keep failed rule metadata for Livewire and the original scoped messages.
                foreach ($elementFailures[$path] as $rule => $parameters) {
                    $validator->addFailure($path, $rule, $parameters);
                }
                $validator->errors()->forget($path);
                foreach ($messages as $message) {
                    $validator->errors()->add($path, $message);
                }
            }
            foreach ($blocks as $index => $block) {
                if (! is_array($block['data'] ?? null)) {
                    continue;
                }
                $data = $block['data'];
                foreach (['x_pt' => ['width_pt', $document->width], 'y_pt' => ['height_pt', $document->height]] as $coordinate => [$size, $limit]) {
                    if (is_numeric($data[$coordinate] ?? null) && is_numeric($data[$size] ?? null) && (float) $data[$coordinate] + (float) $data[$size] > $limit + .001) {
                        $validator->errors()->add('blocks.'.$index.'.data.'.$coordinate, __('filament-canvas::canvas.bounds_error'));
                    }
                }
            }
        });
        $validator->validate();
    }
}
