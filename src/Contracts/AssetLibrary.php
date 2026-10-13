<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;

interface AssetLibrary
{
    /** @return array<int, array{id: int|string, name: string, image: string}> */
    public function assets(EditorContext $context): array;
}
