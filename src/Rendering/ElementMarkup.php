<?php

namespace EduardoRibeiroDev\FilamentCanvas\Rendering;

use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;

/** Carry prepared values alongside the template for standalone previews and exports. */
class ElementMarkup
{
    /** All element views are Alpine templates; compilation belongs to the rendering pipeline. */
    public static function template(ElementType $element): string
    {
        return view($element->view())->render().view('filament-canvas::elements.link')->render();
    }

    public static function wrap(string $html, array $values): string
    {
        $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<div data-canvas-values="'.Html::escape($json).'" style="display:contents">'.$html.'</div>';
    }
}
