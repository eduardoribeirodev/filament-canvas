<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Models\Concerns\HasCanvas;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class TestDocument extends Model implements CanvasOwner, HasMedia
{
    use HasCanvas;
    use InteractsWithMedia;

    protected $table = 'fixture_documents';

    protected $guarded = ['id'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('library')->useDisk('public');
    }
}
