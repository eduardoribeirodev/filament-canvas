<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Elements\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Livewire\CanvasEditor;
use EduardoRibeiroDev\FilamentCanvas\Support\CanvasElements;
use EduardoRibeiroDev\FilamentCanvas\Support\MediaLibrary;
use EduardoRibeiroDev\FilamentCanvas\Tests\Support\TestDocument;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaLibraryTest extends TestCase
{
    private ?int $hiddenOwner = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useCanvasFixtures();
        Gate::before(fn ($user, $ability, $arguments) => ! (($arguments[0] ?? null) instanceof TestDocument && $arguments[0]->id === $this->hiddenOwner));
    }

    public function test_default_collections_list_only_the_document_and_its_elements(): void
    {
        $owner = TestDocument::create();
        $own = $this->image($owner, 'own.png');
        $this->image(TestDocument::create(), 'foreign.png');
        $element = $owner->canvasElements()->create(['type' => 'asset', ...$this->block()['data']]);
        $attached = $element->addMedia(UploadedFile::fake()->image('attached.png'))->toMediaCollection('image');
        $library = new MediaLibrary($this->context($owner));
        $this->assertEqualsCanonicalizing([$own->id, $attached->id], array_column($library->assets(new ImageElement, 'image'), 'id'));
    }

    public function test_each_collection_applies_its_query_and_owner_permissions(): void
    {
        $owner = TestDocument::create();
        $other = TestDocument::create();
        $this->hiddenOwner = $other->id;
        $logo = $this->image($owner, 'logo.png');
        $photo = $this->image($owner, 'photo.png');
        $foreign = $this->image($other, 'logo.png');
        $element = $this->filteredElement();
        $library = new MediaLibrary($this->context($owner));
        $this->assertSame([$logo->id], array_column($library->assets($element, 'image'), 'id'));
        $this->assertSame([$photo->id], array_column($library->assets($element, 'gallery'), 'id'));
        $this->assertNull($library->find($foreign->id, $element, 'image'));
        $this->assertNull($library->find($photo->id, $element, 'image'));
        $metadata = CanvasElements::metadataForEditor(CanvasElements::list([$element]));
        $this->assertArrayNotHasKey('query', $metadata['asset']['media_collections']['image']);
        json_encode($metadata, JSON_THROW_ON_ERROR);
    }

    public function test_resolution_ignores_a_rejected_ids_fallback_url(): void
    {
        $owner = TestDocument::create();
        $logo = $this->image($owner, 'logo.png');
        $photo = $this->image($owner, 'photo.png');
        $element = $this->filteredElement();
        $block = $this->block();
        $block['data'] += ['image_id' => $photo->id, 'image_url' => $photo->getUrl(), 'gallery_ids' => [$logo->id, $photo->id]];
        $media = (new MediaLibrary($this->context($owner)))->resolve($element, $block);
        $this->assertSame('', $media['image']['image']);
        $this->assertSame([$photo->id], array_column($media['gallery'], 'id'));
    }

    public function test_save_rejects_filtered_ids_and_preserves_copied_media_on_the_next_save(): void
    {
        $owner = TestDocument::create();
        $logo = $this->image($owner, 'logo.png');
        $photo = $this->image($owner, 'photo.png');
        $store = $this->plugin($this->filteredElement())->resolveDocumentStore('document');
        $context = $this->context($owner);
        $initial = $store->load($context);
        $block = $this->block();
        $block['data']['image_id'] = $photo->id;
        try {
            $store->save($context, [$block], $initial->revision);
            $this->fail('A filtered source must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(0, $owner->canvasElements()->count());
        }
        $block['data']['image_id'] = $logo->id;
        $saved = $store->save($context, [$block], $initial->revision);
        $this->assertNotSame($logo->id, $saved->blocks[0]['data']['image_id']);
        $this->assertSame($saved->revision, $store->save($context, $saved->blocks, $saved->revision)->revision);
    }

    public function test_upload_uses_the_collection_and_keeps_the_callers_file(): void
    {
        $owner = TestDocument::create();
        $element = $this->filteredElement();
        $library = new MediaLibrary($this->context($owner));
        $file = UploadedFile::fake()->image('logo.png');
        $asset = $library->upload($file, $element, 'image');
        $this->assertFileExists($file->getRealPath());
        $this->assertSame('logos', Media::findOrFail($asset['id'])->collection_name);
        $this->assertSame([$asset['id']], array_column($library->assets($element, 'image'), 'id'));
        $this->expectException(ValidationException::class);
        $library->upload(UploadedFile::fake()->create('document.pdf', 1, 'application/pdf'), $element, 'image');
    }

    public function test_upload_that_fails_the_filter_leaves_no_record_or_file(): void
    {
        $owner = TestDocument::create();
        try {
            (new MediaLibrary($this->context($owner)))->upload(UploadedFile::fake()->image('photo.png'), $this->filteredElement(), 'image');
            $this->fail('The upload must satisfy the query.');
        } catch (ValidationException) {
            $this->assertSame(0, Media::count());
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_native_filament_labels_and_icons_work_when_creating_an_element(): void
    {
        $element = new class extends TextElement
        {
            public function getIcon(): Heroicon
            {
                return Heroicon::OutlinedTag;
            }

            public function getLabel(): HtmlString
            {
                return new HtmlString('Selo');
            }
        };
        $this->assertInstanceOf(HasIcon::class, $element);
        $this->assertInstanceOf(HasLabel::class, $element);
        $definitions = CanvasElements::list([$element]);
        $this->assertSame(['text' => 'Selo'], CanvasElements::labels($definitions));
        $this->assertSame(Heroicon::OutlinedTag->value, CanvasElements::metadataForEditor($definitions)['text']['icon']);
        $owner = TestDocument::create();
        $this->panel($this->plugin($element));
        $editor = Livewire::test(CanvasEditor::class, ['documentType' => 'document', 'documentId' => $owner->id, 'panelId' => 'direct-media'])
            ->call('createElement', 'text')->assertHasNoErrors();
        $this->assertSame('Selo', $editor->effects['returns'][0]['data']['name']);
    }

    public function test_editor_pickers_and_uploads_use_the_selected_collection(): void
    {
        $owner = TestDocument::create();
        $logo = $this->image($owner, 'logo.png');
        $photo = $this->image($owner, 'photo.png');
        $this->panel($this->plugin($this->filteredElement()));
        $editor = Livewire::test(CanvasEditor::class, ['documentType' => 'document', 'documentId' => $owner->id, 'panelId' => 'direct-media'])
            ->call('selectInspector', 'asset', 'image-one', $this->block(), 1)->assertHasNoErrors();
        $this->assertSame([$logo->id], array_keys($editor->instance()->assetOptions('', 'image')));
        $this->assertSame([$photo->id], array_keys($editor->instance()->assetOptions('', 'gallery')));
        $editor->set('imageUploadData.file', UploadedFile::fake()->image('logo.png'))->call('uploadAsset', 'image')->assertHasNoErrors();
        $uploaded = $editor->effects['returns'][0];
        $this->assertSame('logos', Media::findOrFail($uploaded['asset']['id'])->collection_name);
        $this->assertCount(2, $editor->instance()->assetOptions('', 'image'));
    }

    public function test_custom_elements_can_declare_media_without_inheriting_image_element(): void
    {
        $owner = TestDocument::create();
        $logo = $this->image($owner, 'logo.png');
        $element = new class extends CanvasElement
        {
            public function type(): string
            {
                return 'custom';
            }

            public function getLabel(): string
            {
                return 'Custom';
            }

            public function view(): string
            {
                return 'filament-canvas::elements.image';
            }

            public function mediaCollections(): array
            {
                return ['image' => ['query' => fn (Builder $query) => $query->where('name', 'logo')]];
            }
        };
        $block = $this->block();
        $block['type'] = 'custom';
        $block['data']['image_id'] = $logo->id;
        $html = $this->plugin($element)->resolveRenderer('document')->render(new CanvasDocument([$block], 1920, 1080), $this->context($owner));
        $this->assertStringContainsString($logo->getUrl(), $html);
    }

    private function filteredElement(): ImageElement
    {
        return new class extends ImageElement
        {
            public function mediaCollections(): array
            {
                return ['image' => [...parent::mediaCollections()['image'],
                    'query' => fn (Builder $query) => $query->where('name', 'logo'), 'upload_collection' => 'logos'],
                    'gallery' => ['reference_field' => 'gallery_ids', 'max_files' => 2,
                        'query' => fn (Builder $query) => $query->where('name', 'photo')]];
            }
        };
    }

    private function plugin($element): CanvasPlugin
    {
        return CanvasPlugin::make()->forModel('document', TestDocument::class, ['elements' => [$element]]);
    }

    private function panel(CanvasPlugin $plugin): void
    {
        $panel = Panel::make()->id('direct-media')->plugin($plugin);
        app(PanelRegistry::class)->register($panel);
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();
    }

    private function context(TestDocument $owner): EditorContext
    {
        return (new EditorContext(new DocumentReference('document', $owner->id), auth()->user()))->forDocument($owner);
    }

    private function image(TestDocument $owner, string $name): Media
    {
        return $owner->addMedia(UploadedFile::fake()->image($name))->toMediaCollection('library');
    }

    private function block(): array
    {
        return ['type' => 'asset', 'data' => ['name' => 'Image', 'x_pt' => 0, 'y_pt' => 0,
            'width_pt' => 100, 'height_pt' => 100, 'rotation_deg' => 0, 'visible' => true, 'properties' => []]];
    }
}
