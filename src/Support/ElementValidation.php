<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use BackedEnum;
use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/** Evaluate native field rules for payloads, also outside an active Livewire editor. */
final class ElementValidation
{
    public static function rules(ElementType $element, array $properties = []): array
    {
        $host = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $properties = [];
        };
        // Never fill/hydrate: defaults and casts must not overwrite imported/raw values.
        $host->properties = $properties;
        $schema = $element->form(Schema::make($host)->statePath('properties'));
        $structural = [];
        self::collectStructure($schema, $structural);
        $validator = Validator::make(['properties' => $properties], $structural);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->toArray() as $path => $messages) {
                $errors[substr($path, strlen('properties.'))] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $rules = [];
        if ($element instanceof TextElement) {
            // Generated caches are part of the text format, not configurable fields.
            $rules = ['html' => ['nullable', 'string', 'max:200000'], 'text' => ['nullable', 'string', 'max:20000']];
            // Saved base colors still participate in rendering; new colors are rich text marks.
            $rules['color'] = ['nullable', 'regex:/^#(?:[a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})$/iD'];
        }
        if ($element instanceof ImageElement) {
            $rules = [
                'crop_x' => ['nullable', 'numeric', 'between:0,1'],
                'crop_y' => ['nullable', 'numeric', 'between:0,1'],
                'image_transform' => ['nullable', 'array:x,y,width,height,rotation'],
            ];
            foreach (['x', 'y', 'width', 'height', 'rotation'] as $coordinate) {
                $bounds = match ($coordinate) {
                    'width', 'height' => ['gt:0', 'max:10000'],
                    'rotation' => ['between:-360,360'],
                    default => ['between:-10000,10000'],
                };
                $rules['image_transform.'.$coordinate] = ['required_with:image_transform', 'numeric', ...$bounds];
            }
        }
        $formRules = [];
        self::collect($schema, $formRules);
        foreach ($formRules as $path => $fieldRules) {
            $path = substr($path, strlen('properties.'));
            $fieldRules = array_map(function ($rule) {
                if (is_string($rule) && preg_match('/^(?:required_(?:with|without)(?:_all)?|same|different|gt|gte|lt|lte|prohibited_(?:if|unless)|exclude_(?:if|unless)|required_(?:if|unless)):/', $rule)) {
                    return str_replace('properties.', '', $rule);
                }

                return $rule;
            }, $fieldRules);
            $rules[$path] = [...($rules[$path] ?? []), ...$fieldRules];
        }

        // A dotted field declares its parent container as well.
        foreach (array_keys($rules) as $path) {
            while (str_contains($path, '.')) {
                $path = substr($path, 0, strrpos($path, '.'));
                $rules[$path] ??= ['nullable', 'array'];
            }
        }
        $allowed = array_unique(array_map(fn ($path) => explode('.', $path)[0], array_keys($rules)));
        foreach (array_diff(array_keys($properties), $allowed) as $property) {
            $rules[$property] = ['missing'];
        }

        return $rules;
    }

    public static function validate(ElementType $element, array $properties): void
    {
        Validator::make($properties, self::rules($element, $properties))->validate();
    }

    private static function collect(Schema $schema, array &$rules, bool $hidden = false): void
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            $componentHidden = $hidden || $component->isHidden();
            if ($component instanceof Field) {
                $component->dehydrateValidationRules($rules);
                $path = $component->getStatePath();
                // Hidden values still need their type/limits validated when supplied.
                if ($componentHidden) {
                    $rules[$path] = array_values(array_filter($rules[$path] ?? [], fn ($rule) => ! is_string($rule) || ! preg_match('/^required(?:_|$)/', $rule)));
                }
                if ($component instanceof Repeater || $component instanceof CheckboxList || $component instanceof TagsInput
                    || $component instanceof KeyValue || ($component instanceof Select && $component->isMultiple())) {
                    $rules[$path] = ['bail', ...($rules[$path] ?? []), 'array'];
                }
                if ($component instanceof Repeater) {
                    $state = $component->getRawState();
                    if ($state !== null && ! is_array($state)) {
                        continue;
                    }
                    if (is_array($state) && ($limit = $component->getMaxItems()) !== null && count($state) > $limit) {
                        continue;
                    }
                    if (! $component->isSimple()) {
                        $rules[$path.'.*'] = ['array'];
                    }
                }
            }
            foreach ($component->getChildSchemas(withHidden: true) as $child) {
                self::collect($child, $rules, $componentHidden);
            }
        }
    }

    private static function collectStructure(Schema $schema, array &$rules): void
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Select && ! $component->isMultiple()) {
                $rules[$component->getStatePath()] = ['nullable', function ($attribute, $value, $fail): void {
                    if (! is_scalar($value) && ! $value instanceof BackedEnum) {
                        $fail('The selected option must be a scalar value.');
                    }
                }];
            }
            if ($component instanceof Repeater || $component instanceof CheckboxList || $component instanceof TagsInput
                || $component instanceof KeyValue || ($component instanceof Select && $component->isMultiple())) {
                $path = $component->getStatePath();
                $rules[$path] = ['nullable', 'array'];
                $value = $component->getRawState();
                if ($value !== null && ! is_array($value)) {
                    continue;
                }
                if ($component instanceof Repeater) {
                    if (! $component->isSimple()) {
                        $rules[$path.'.*'] = ['array'];
                        if (is_array($value) && array_filter($value, fn ($item) => ! is_array($item))) {
                            continue;
                        }
                    }
                    if (($limit = $component->getMaxItems()) !== null) {
                        $rules[$path][] = 'max:'.$limit;
                        if (is_array($value) && count($value) > $limit) {
                            continue;
                        }
                    }
                }
            }
            foreach ($component->getChildSchemas(withHidden: true) as $child) {
                self::collectStructure($child, $rules);
            }
        }
    }
}
