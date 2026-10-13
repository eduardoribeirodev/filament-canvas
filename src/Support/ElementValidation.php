<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use BackedEnum;
use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
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
    public static function rules(ElementType $element, array $dados = [], string $prefix = ''): array
    {
        $host = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $properties = [];
        };
        // Never fill/hydrate: defaults and casts must not overwrite imported/raw values.
        $host->properties = $dados;
        $schema = $element->properties(Schema::make($host)->statePath('properties'));
        $structural = [];
        self::collectStructure($schema, $structural);
        $validator = Validator::make(['properties' => $dados], $structural);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->toArray() as $path => $messages) {
                $errors[$prefix.substr($path, strlen('properties.'))] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $rules = $element->validationRules($prefix);
        $formRules = [];
        self::collect($schema, $formRules);
        foreach ($formRules as $path => $fieldRules) {
            $path = $prefix.substr($path, strlen('properties.'));
            $fieldRules = array_map(function ($rule) use ($prefix) {
                if (is_string($rule) && preg_match('/^(?:required_(?:with|without)(?:_all)?|same|different|gt|gte|lt|lte|prohibited_(?:if|unless)|exclude_(?:if|unless)|required_(?:if|unless)):/', $rule)) {
                    return str_replace('properties.', $prefix, $rule);
                }

                return $rule;
            }, $fieldRules);
            $rules[$path] = [...($rules[$path] ?? []), ...$fieldRules];
        }

        return $rules;
    }

    public static function validate(ElementType $element, array $dados): void
    {
        Validator::make($dados, self::rules($element, $dados))->validate();
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
                        $fail('A opção selecionada deve ser um valor simples.');
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
