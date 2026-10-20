<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Contracts\DocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Contracts\PreviewRenderer;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Data\SaveResult;
use EduardoRibeiroDev\FilamentCanvas\Elements\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Livewire\CanvasEditor;
use EduardoRibeiroDev\FilamentCanvas\Rendering\ElementViewData;
use EduardoRibeiroDev\FilamentCanvas\Tests\Support\TestDocument;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Schemas\Schema;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class InspectorTest extends TestCase
{
    private string $dynamicElementInspectorViewDirectory;

    protected function tearDown(): void
    {
        if (isset($this->dynamicElementInspectorViewDirectory)) {
            @unlink($this->dynamicElementInspectorViewDirectory.'/card.blade.php');
            @unlink($this->dynamicElementInspectorViewDirectory.'/media.blade.php');
            @rmdir($this->dynamicElementInspectorViewDirectory);
        }
        parent::tearDown();
    }

    public function test_native_schema_defaults_initialize_new_types_before_the_inspector_is_opened(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $editor = $this->dynamicElementInspectorEditor($edition)->call('createElement', 'test-card')->assertHasNoErrors();
        $created = $editor->effects['returns'][0];
        $this->assertSame('Cartão', $created['data']['name']);
        $this->assertSame('Novo cartão', $created['data']['properties']['title']);
        $this->assertFalse($created['data']['properties']['enabled']);
        $this->assertSame(0, $created['data']['properties']['count']);
        $this->assertSame('#111111', $created['data']['properties']['preferences']['color']);
        $this->assertNull($editor->get('inspectorType'));
        $this->assertSame(0, $edition->canvasElements()->count());
    }

    public function test_editor_passes_configured_zoom_limits_to_its_alpine_component(): void
    {
        config(['filament-canvas.min_zoom' => .1, 'filament-canvas.max_zoom' => 5]);
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $html = $this->dynamicElementInspectorEditor($edition)->html();
        $this->assertStringContainsString('minZoom: 0.1', $html);
        $this->assertStringContainsString('maxZoom: 5', $html);
        $this->assertStringContainsString('minValue: minZoom * 100', $html);
        $this->assertStringContainsString('maxValue: maxZoom * 100', $html);
    }

    public function test_native_repeater_actions_resolve_the_properties_schema_and_preserve_unsaved_state(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        Filament::getCurrentPanel()->getPlugin('filament-canvas')->documentElements('test-document', ['data-table']);
        $block = $this->dynamicElementInspectorBlock('Table');
        $block['type'] = 'data-table';
        $block['data']['properties'] = ['columns' => [['label' => 'Name', 'field' => 'name']], 'rows' => [['name' => 'Original']]];
        $editor = $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'data-table', 'table', $block, 1);
        $this->assertNotNull($editor->instance()->getSchemaComponent('propertiesForm.rows'));
        $this->assertStringContainsString('propertiesForm.rows', $editor->html());
        $editor->call('mountAction', 'add', [], ['schemaComponent' => 'propertiesForm.rows'])->assertHasNoErrors();
        $rows = array_values($editor->get('inspectorData.rows'));
        $this->assertCount(2, $rows);
        $this->assertSame('Original', $rows[0]['name']);
        $this->assertSame(0, $edition->canvasElements()->count());
    }

    public function test_class_schema_drives_properties_and_preserves_unrepresented_nested_values(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $block = $this->dynamicElementInspectorBlock('Primeiro');
        $editor = $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-card', 'first', $block, 1)->assertSee('Text do cartão')->assertSee('Color do cartão')->assertHasNoErrors();
        $this->assertFalse($editor->get('inspectorData.enabled'));
        $this->assertEquals(0, $editor->get('inspectorData.count'));
        $this->assertNull($editor->get('inspectorData.title'));
        $values = $editor->get('inspectorData');
        $values['title'] = 'Atualizado <script>';
        $values['preferences']['color'] = '#ff0000';
        $editor->call('updateInspector', $values, 'first', 1, 1, $block)->assertHasNoErrors();
        $result = $editor->effects['returns'][0];
        $this->assertSame('Atualizado <script>', $result['patch']['title']);
        $this->assertSame(['color' => '#ff0000', 'row_slots' => [[1, 3], [2]]], $result['patch']['preferences']);
        $this->assertArrayNotHasKey('html', $result, 'Property validation must not render another view.');
        $this->assertSame(0, $edition->canvasElements()->count());
    }

    public function test_missing_toggle_state_keeps_its_schema_default_without_replacing_false_or_zero(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $block = $this->dynamicElementInspectorBlock('Imported');
        $editor = $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-card', 'first', $block, 1)->assertHasNoErrors();
        $this->assertTrue($editor->get('inspectorData.show_rule'));
        $this->assertFalse($editor->get('inspectorData.enabled'));
        $this->assertEquals(0, $editor->get('inspectorData.count'));
        $block['data']['properties']['show_rule'] = false;
        $editor->call('selectInspector', 'test-card', 'second', $block, 2)->assertHasNoErrors();
        $this->assertFalse($editor->get('inspectorData.show_rule'));
    }

    public function test_stale_form_updates_cannot_replace_a_new_selection(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $first = $this->dynamicElementInspectorBlock('Primeiro');
        $second = $this->dynamicElementInspectorBlock('Segundo');
        $second['data']['properties']['title'] = 'Segundo';
        $editor = $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-card', 'first', $first, 1)->call('selectInspector', 'test-card', 'second', $second, 2)->call('updateInspector', ['title' => 'Evento atrasado'], 'first', 1, 1, $first)->assertHasNoErrors();
        $this->assertTrue($editor->effects['returns'][0]['stale']);
        $this->assertSame('second', $editor->get('inspectorKey'));
        $this->assertSame('Segundo', $editor->get('inspectorData.title'));
        $editor->call('selectInspector', 'test-card', 'first', $first, 1);
        $this->assertTrue($editor->effects['returns'][0]['stale']);
        $this->assertSame('second', $editor->get('inspectorKey'));
    }

    public function test_custom_views_are_available_as_templates_without_render_hooks(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $block = $this->dynamicElementInspectorBlock('Card');
        $block['data']['properties']['title'] = 'Cartão <seguro>';
        $editor = $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-card', 'first', $block, 1)->assertHasNoErrors();
        $html = $editor->instance()->render()->getData()['elementTemplates']['test-card'];
        $this->assertStringContainsString('data-test-card', $html);
        $this->assertStringContainsString('x-text="data.title', $html);
        $this->assertStringNotContainsString('<seguro>', $html);
        $this->assertSame('Cartão <seguro>', $editor->get('inspectorData.title'));
        $this->assertSame(0, $edition->canvasElements()->count());
    }

    public function test_declared_media_collections_drive_native_picker_upload_constraints_and_rendered_references(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $plugin = Filament::getCurrentPanel()->getPlugin('filament-canvas');
        $plugin->element(new InspectorMediaElement)->documentElements('test-document', ['test-card', 'test-media']);
        $context = new EditorContext(new DocumentReference('test-document', $edition->id), auth()->user(), 'canvas-dynamic-inspector-test');
        $pngMedia = $edition->addMedia(UploadedFile::fake()->image('Capa.png'))->toMediaCollection('library');
        $png = ['id' => $pngMedia->id, 'image' => $pngMedia->getUrl()];
        $jpeg = ['id' => $edition->addMedia(UploadedFile::fake()->image('Photo.jpg'))->toMediaCollection('library')->id];
        $block = $this->dynamicElementInspectorBlock('Medias');
        $block['type'] = 'test-media';
        $block['data']['properties'] = [];
        $block['data']['cover_asset'] = $png['id'];
        $block['data']['gallery_assets'] = [$png['id']];
        $editor = $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-media', 'media', $block, 1)->assertHasNoErrors();
        $component = $editor->instance();
        $policies = $component->elementMediaCollections();
        $this->assertSame(['cover', 'gallery'], array_column($policies, 'name'));
        $this->assertSame(['cover_asset', 'gallery_assets'], array_column($policies, 'reference_field'));
        $this->assertArrayHasKey($png['id'], $component->assetOptions('', 'cover'));
        $this->assertArrayNotHasKey($jpeg['id'], $component->assetOptions('', 'cover'));
        $this->assertTrue($component->imageForm->getComponentByStatePath('collections.gallery')->isMultiple());
        $this->assertSame(2, $component->imageForm->getComponentByStatePath('collections.gallery')->getMaxItems());
        $this->assertSame(['image/png'], $component->imageUploadForm->getComponentByStatePath('file')->getAcceptedFileTypes());
        $this->assertSame(2, $component->imageUploadForm->getComponentByStatePath('file')->getMaxSize());
        $this->assertSame(2, $component->imageUploadForm->getComponentByStatePath('collections.gallery.file')->getMaxFiles());
        $values = app(ElementViewData::class)->prepare(new InspectorMediaElement, $block, $context->forDocument($edition));
        $this->assertSame($png['image'], $values['media']['cover']['image']);
        $this->assertSame($png['image'], $values['media']['gallery'][0]['image']);
    }

    public function test_upload_rpc_enforces_the_selected_collections_mime_size_and_file_count(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        Filament::getCurrentPanel()->getPlugin('filament-canvas')->element(new InspectorMediaElement)->documentElements('test-document', ['test-card', 'test-media']);
        config()->set('livewire.temporary_file_upload.disk', 'canvas_temporary_uploads');
        Storage::fake('canvas_temporary_uploads');
        $block = $this->dynamicElementInspectorBlock('Medias');
        $block['type'] = 'test-media';
        $block['data']['properties'] = [];
        $editor = $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-media', 'media', $block, 1);
        $editor->set('imageUploadData.file', [UploadedFile::fake()->image('unsupported.jpg')])->call('uploadAsset', 'cover')->assertHasErrors();
        $editor->set('imageUploadData.file', [UploadedFile::fake()->image('oversized.png')->size(3)])->call('uploadAsset', 'cover')->assertHasErrors();
        $editor->set('imageUploadData.file', null)->set('imageUploadData.collections.gallery.file', [UploadedFile::fake()->image('one.png'), UploadedFile::fake()->image('two.png'), UploadedFile::fake()->image('three.png')])->call('uploadAsset', 'gallery')->assertHasErrors();
        $this->assertSame(0, Media::count());
        $editor = $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-media', 'media', $block, 1);
        $editor->set('imageUploadData.collections.gallery.file', [UploadedFile::fake()->image('one.png'), UploadedFile::fake()->image('two.png')])->call('uploadAsset', 'gallery')->assertHasNoErrors();
        $uploaded = $editor->effects['returns'][0];
        $this->assertSame('gallery', $uploaded['collection']);
        $this->assertCount(2, $uploaded['inserted']);
        $this->assertCount(2, $uploaded['assets']);
        $this->assertEmpty($editor->get('imageUploadData.collections.gallery.file'));
    }

    public function test_malformed_element_envelopes_are_rejected_before_class_normalization(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-card', 'first', ['type' => 'test-card', 'data' => 'invalid'], 1)->assertHasErrors('data');
        $block = $this->dynamicElementInspectorBlock('Card');
        $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-card', 'first', $block, 1)
            ->call('updateInspector', [], 'first', 1, 1, ['type' => 'test-card', 'data' => ['properties' => 'invalid']])->assertHasErrors('data.properties');
    }

    private function dynamicElementInspectorPrepareEditor(): TestDocument
    {
        $this->useCanvasFixtures();
        Gate::before(fn () => true);
        $panel = $this->canvasPanel('canvas-dynamic-inspector-test');
        $panel->getPlugin('filament-canvas')->element(new InspectorCardElement)->documentElements('test-document', ['test-card'])->editorView('test-document', 'filament-canvas::editor');
        $this->dynamicElementInspectorViewDirectory = sys_get_temp_dir().'/canvas-inspector-'.bin2hex(random_bytes(8));
        mkdir($this->dynamicElementInspectorViewDirectory);
        file_put_contents($this->dynamicElementInspectorViewDirectory.'/card.blade.php', <<<'BLADE'
            <div data-test-card :style="{color:data.preferences.color ?? '#222222'}" x-text="data.title ?? ''"></div>
            BLADE);
        file_put_contents($this->dynamicElementInspectorViewDirectory.'/media.blade.php', <<<'BLADE'
            <img :src="data.media.cover.image || null"><template x-for="asset in data.media.gallery"><img :src="asset.image"></template>
            BLADE);
        $this->app['view']->addNamespace('canvas-inspector-tests', $this->dynamicElementInspectorViewDirectory);

        return TestDocument::create();
    }

    private function dynamicElementInspectorEditor(TestDocument $edition): Testable
    {
        return Livewire::test(CanvasEditor::class, ['documentType' => 'test-document', 'documentId' => $edition->id, 'panelId' => 'canvas-dynamic-inspector-test']);
    }

    private function dynamicElementInspectorBlock(string $name): array
    {
        return ['type' => 'test-card', 'data' => ['name' => $name, 'x_pt' => 10, 'y_pt' => 10, 'width_pt' => 200, 'height_pt' => 40, 'rotation_deg' => 0, 'visible' => true,  'properties' => ['title' => null, 'enabled' => false, 'count' => 0, 'preferences' => ['color' => '#111111', 'row_slots' => [[1, 3], [2]]]]]];
    }

    public function test_generic_canvas_uses_host_contracts_for_load_preview_assets_and_save(): void
    {
        $user = new GenericUser(['id' => 12]);
        auth()->setUser($user);
        $blocks = [['type' => 'text', 'data' => $this->filamentPackagesElementData()]];
        unset($blocks[0]['data']['type'], $blocks[0]['data']['layer']);
        $canonical = $blocks;
        $canonical[0]['data']['properties'] += ['rich_text' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => 'Text original']]]]], 'html' => '<p style="text-align: center;">Text original</p>'];
        $saved = $canonical;
        $saved[0]['data']['id'] = 'external-node';
        $store = Mockery::mock(DocumentStore::class);
        $store->shouldReceive('authorize')->withArgs(function (EditorContext $context, string $ability) use ($user) {
            $this->assertSame('generic', $context->reference->type);
            $this->assertSame('document-1', $context->reference->id);
            $this->assertSame($user, $context->user);
            $this->assertSame('canvas-test', $context->panelId);

            return in_array($ability, ['view', 'preview', 'update'], true);
        });
        $store->shouldReceive('load')->andReturn(new CanvasDocument([], 300, 200, 'revision-1', ['documentTitle' => 'Canvas genérico']));
        $store->shouldReceive('save')->once()->withArgs(fn (EditorContext $context, array $payload, string $revision) => $payload === $canonical && $revision === 'revision-1')->andReturn(new SaveResult($saved, 'revision-2'));
        $renderer = Mockery::mock(PreviewRenderer::class);
        $renderer->shouldReceive('render')->andReturnUsing(fn (CanvasDocument $document) => '<div class="page">'.count($document->blocks).'</div>');
        app(PanelRegistry::class)->register(Panel::make()->id('canvas-test')->plugin(CanvasPlugin::make()->documentStore('generic', $store)->previewRenderer('generic', $renderer)));
        $editor = new CanvasEditor;
        $editor->mount('generic', 'document-1', 'canvas-test');
        $this->assertSame('revision-1', $editor->revision);
        $this->assertSame('<div class="page">1</div>', $editor->preview($blocks));
        $this->assertSame($saved, $editor->save($blocks));
        $this->assertSame('revision-2', $editor->revision);
        $view = $editor->render()->getData();
        $this->assertSame($saved, $view['initial']);
        $this->assertSame('Canvas genérico', $view['metadata']['documentTitle']);
        $this->assertSame([], $view['assets']);
    }

    public function test_generic_canvas_rejects_out_of_bounds_elements_before_the_host_store_saves(): void
    {
        $store = Mockery::mock(DocumentStore::class);
        $store->shouldReceive('authorize');
        $store->shouldReceive('load')->andReturn(new CanvasDocument([], 100, 100, 'revision-1'));
        $store->shouldNotReceive('save');
        app(PanelRegistry::class)->register(Panel::make()->id('canvas-test')->plugin(CanvasPlugin::make()->documentStore('generic', $store)));
        $editor = new CanvasEditor;
        $editor->mount('generic', 'document-1', 'canvas-test');
        $data = $this->filamentPackagesElementData();
        unset($data['type'], $data['layer']);
        $data['width_pt'] = 90;
        $data['height_pt'] = 50;
        $this->expectException(ValidationException::class);
        $editor->save([['type' => 'text', 'data' => $data]]);
    }

    private function filamentPackagesElementData(): array
    {
        return ['name' => 'Text', 'type' => 'text', 'x_pt' => 20, 'y_pt' => 20, 'width_pt' => 200, 'height_pt' => 100, 'rotation_deg' => 0, 'layer' => 0, 'visible' => true, 'properties' => ['text' => 'Text original', 'rich_text' => $this->textDocument('Text original')], 'references' => []];
    }
}
class InspectorCardElement extends CanvasElement
{
    public function type(): string
    {
        return 'test-card';
    }

    public function getLabel(): string
    {
        return 'Cartão';
    }

    public function view(): string
    {
        return 'canvas-inspector-tests::card';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->rules(['string'])->label('Text do cartão')->default('Novo cartão')->maxLength(100), Toggle::make('enabled')->label('Habilitado')->default(false), Toggle::make('show_rule')->default(true), TextInput::make('count')->label('Contagem')->numeric()->default(0), ColorPicker::make('preferences.color')->label('Color do cartão')->default('#111111')]);
    }
}
class InspectorMediaElement extends ImageElement
{
    public function type(): string
    {
        return 'test-media';
    }

    public function view(): string
    {
        return 'canvas-inspector-tests::media';
    }

    public function mediaCollections(): array
    {
        return ['cover' => ['label' => 'Capa', 'accepted_mime_types' => ['image/png'], 'max_size' => 2, 'max_files' => 1, 'reference_field' => 'cover_asset'], 'gallery' => ['label' => 'Galeria', 'accepted_mime_types' => ['image/png'], 'max_size' => 64, 'max_files' => 2, 'reference_field' => 'gallery_assets']];
    }
}
