<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;

interface HasElementMedia
{
    /** Keyed collection declarations: label, accepted_mime_types, max_files, max_size (KB), reference_field. */
    public function mediaCollections(): array;

    public function withAssetLibrary(AssetLibrary $library): static;

    /** Resolve only authorized assets in this document's context. */
    public function resolveMedia(array $block, EditorContext $context): array;
}
