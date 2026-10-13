<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\UploadableAssetLibrary;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class TestAssetLibrary implements UploadableAssetLibrary
{
    public function assets(EditorContext $context): array
    {
        return TestDocument::findOrFail($context->reference->id)->getMedia('library')
            ->map(fn (Media $media) => $this->describe($media))->all();
    }

    public function upload(EditorContext $context, UploadedFile $file): array
    {
        $media = TestDocument::findOrFail($context->reference->id)->addMedia($file)->toMediaCollection('library');

        return $this->describe($media);
    }

    private function describe(Media $media): array
    {
        return ['id' => $media->id, 'name' => $media->name, 'image' => $media->getUrl(), 'mime_type' => $media->mime_type];
    }
}
