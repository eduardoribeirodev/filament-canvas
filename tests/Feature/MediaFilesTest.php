<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\Support\MediaFiles;
use EduardoRibeiroDev\FilamentCanvas\Tests\Support\TestDocument;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class MediaFilesTest extends TestCase
{
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useCanvasFixtures();
        config()->set('filesystems.disks.canvas-private', ['driver' => 'local', 'root' => storage_path('app/canvas-private'), 'visibility' => 'private']);
        Storage::fake('canvas-private');
        $this->actingAs((new User)->forceFill(['id' => 1]));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            is_dir($path) ? File::deleteDirectory($path) : @unlink($path);
        }
        parent::tearDown();
    }

    public function test_package_serves_private_media_with_a_signed_url_for_the_current_user(): void
    {
        $media = TestDocument::create()->addMedia(UploadedFile::fake()->image('private.png'))->toMediaCollection('private', 'canvas-private');
        $url = MediaFiles::url($media);
        $this->assertStringContainsString('/filament-canvas/files/', $url);
        $this->assertStringNotContainsString('private.png', $url);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs((new User)->forceFill(['id' => 2]));
        $this->get($url)->assertForbidden();
    }

    public function test_tampered_and_expired_urls_are_rejected(): void
    {
        $media = TestDocument::create()->addMedia(UploadedFile::fake()->image('private.png'))->toMediaCollection('private', 'canvas-private');
        $url = MediaFiles::url($media);
        $this->get($url.'&changed=1')->assertForbidden();
        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();
    }

    public function test_even_a_valid_signature_cannot_read_a_file_outside_configured_roots(): void
    {
        $file = UploadedFile::fake()->image('outside.png')->getRealPath();
        $this->assertNull(MediaFiles::renderUrl($file, [], true));
        $url = URL::temporarySignedRoute('filament-canvas.file', now()->addMinutes(30), [
            'file' => strtr(Crypt::encryptString($file), '+/', '-_'), 'user' => 1, 'guard' => 'web',
        ]);
        $this->get($url)->assertNotFound();
    }

    public function test_preview_checks_real_paths_and_file_contents_before_serving(): void
    {
        Storage::disk('canvas-private')->put('fake.png', '<?php echo "unsafe";');
        $url = MediaFiles::renderUrl(Storage::disk('canvas-private')->path('fake.png'), [], true);
        $this->get($url)->assertForbidden();
        $outside = UploadedFile::fake()->image('outside.png')->getRealPath();
        $link = Storage::disk('canvas-private')->path('linked.png');
        symlink($outside, $link);
        $this->temporary[] = $link;
        $this->assertNull(MediaFiles::renderUrl($link, [], true));
    }

    public function test_pdf_image_preparation_still_resizes_and_caches_local_images(): void
    {
        $media = TestDocument::create()->addMedia(UploadedFile::fake()->image('large.png', 900, 600))->toMediaCollection('private', 'canvas-private');
        $cache = Storage::disk('canvas-private')->path('pdf-cache');
        $url = MediaFiles::renderUrl($media->getPath(), ['pdf_max_dimension' => 200], false, $cache);
        $path = rawurldecode(parse_url($url, PHP_URL_PATH));
        $this->assertFileExists($path);
        $this->assertSame([200, 133], array_slice(getimagesize($path), 0, 2));
        $this->assertSame($url, MediaFiles::renderUrl($media->getPath(), ['pdf_max_dimension' => 200], false, $cache));
    }
}
