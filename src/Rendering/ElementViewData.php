<?php

namespace EduardoRibeiroDev\FilamentCanvas\Rendering;

use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use EduardoRibeiroDev\FilamentCanvas\Support\MediaLibrary;

/** Prepare display values at the editor/export boundary, before calling an element's view. */
class ElementViewData
{
    public function prepare(ElementType $element, array $block, EditorContext $context): array
    {
        $properties = ElementData::from($block['data'] ?? []);
        if (array_key_exists('image_url', $properties)) {
            $properties['image_url'] = Html::rawImageUrl($properties['image_url']);
        }
        $media = $element instanceof HasElementMedia ? (new MediaLibrary($context))->resolve($element, $block) : [];

        $data = array_replace($properties, [
            'type' => $element->type(),
            'element_kind' => ElementRegistry::kind($element),
            'geometry' => [
                'x' => (float) ($block['data']['x_pt'] ?? 0),
                'y' => (float) ($block['data']['y_pt'] ?? 0),
                'width' => (float) ($block['data']['width_pt'] ?? 200),
                'height' => (float) ($block['data']['height_pt'] ?? 100),
                'rotation' => (float) ($block['data']['rotation_deg'] ?? 0),
            ],
            'media' => $media,
        ]);

        return $data;
    }
}
