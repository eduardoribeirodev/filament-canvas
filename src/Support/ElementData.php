<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;

final class ElementData
{
    public static function normalize(ElementType $element, array $properties): array
    {
        return method_exists($element, 'normalize') ? $element->normalize($properties) : $properties;
    }

    public static function from(array $data): array
    {
        return is_array($data['properties'] ?? null) ? $data['properties'] : [];
    }
}
