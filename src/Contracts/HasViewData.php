<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;

interface HasViewData
{
    /** Extend the native properties, geometry and resolved media for this block. */
    public function viewData(array $data, EditorContext $context): array;
}
