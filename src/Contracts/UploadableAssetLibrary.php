<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use Illuminate\Http\UploadedFile;

/** Optional capability for libraries that accept images directly from the editor. */
interface UploadableAssetLibrary extends AssetLibrary
{
    /** @return array{id: int|string, name: string, image: string, url?: string, mime_type?: string} */
    public function upload(EditorContext $context, UploadedFile $file): array;
}
