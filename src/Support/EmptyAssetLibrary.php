<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\AssetLibrary;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;

class EmptyAssetLibrary implements AssetLibrary
{
    public function assets(EditorContext $context): array
    {
        return [];
    }
}
