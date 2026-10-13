<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\Tests\Support\TestDocument;
use EduardoRibeiroDev\FilamentCanvas\Tests\Support\TestAssetLibrary;
use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Contracts\AssetLibrary;
use EduardoRibeiroDev\FilamentCanvas\Contracts\DocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Contracts\PreviewRenderer;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Data\SaveResult;
use EduardoRibeiroDev\FilamentCanvas\Elements\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Livewire\CanvasEditor;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
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
            @unlink($this->dynamicElementInspectorViewDirectory . '/card.blade.php');
            @unlink($this->dynamicElementInspectorViewDirectory . '/media.blade.php');
            @rmdir($this->dynamicElementInspectorViewDirectory);
        }
        parent::tearDown();
    }
    public function test_native_schema_defaults_initialize_new_types_before_the_inspector_is_opened(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $editor = $this->dynamicElementInspectorEditor($edition)->call('createElement', 'test-card')->assertHasNoErrors();
        $created = $editor->effects['returns'][0];
        $this->assertSame('Cartão', $created['data']['nome']);
        $this->assertSame('Novo cartão', $created['data']['dados']['title']);
        $this->assertFalse($created['data']['dados']['enabled']);
        $this->assertSame(0, $created['data']['dados']['count']);
        $this->assertSame('#111111', $created['data']['dados']['preferences']['color']);
        $this->assertNull($editor->get('inspectorType'));
        $this->assertSame(0, $edition->canvasElements()->count());
    }
    public function test_class_schema_drives_properties_and_preserves_unrepresented_nested_values(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $block = $this->dynamicElementInspectorBlock('Primeiro');
        $editor = $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-card', 'first', $block, 1)->assertSee('Texto do cartão')->assertSee('Cor do cartão')->assertHasNoErrors();
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
        $this->assertSame(['source' => 'imported'], $editor->get('inspectorBlock.data.dados.internal'));
        $this->assertStringContainsString('Atualizado &lt;script&gt;', $result['html']);
        $this->assertSame(0, $edition->canvasElements()->count());
    }
    public function test_stale_form_updates_cannot_replace_a_new_selection(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $first = $this->dynamicElementInspectorBlock('Primeiro');
        $second = $this->dynamicElementInspectorBlock('Segundo');
        $second['data']['dados']['title'] = 'Segundo';
        $editor = $this->dynamicElementInspectorEditor($edition)->call('selectInspector', 'test-card', 'first', $first, 1)->call('selectInspector', 'test-card', 'second', $second, 2)->call('updateInspector', ['title' => 'Evento atrasado'], 'first', 1, 1, $first)->assertHasNoErrors();
        $this->assertTrue($editor->effects['returns'][0]['stale']);
        $this->assertSame('second', $editor->get('inspectorKey'));
        $this->assertSame('Segundo', $editor->get('inspectorData.title'));
        $editor->call('selectInspector', 'test-card', 'first', $first, 1);
        $this->assertTrue($editor->effects['returns'][0]['stale']);
        $this->assertSame('second', $editor->get('inspectorKey'));
    }
    public function test_custom_blade_element_renders_without_a_javascript_renderer(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $block = $this->dynamicElementInspectorBlock('Card');
        $block['data']['dados']['title'] = 'Somente PHP e Blade';
        $editor = $this->dynamicElementInspectorEditor($edition)->call('renderElement', $block)->assertHasNoErrors();
        $html = $editor->effects['returns'][0];
        $this->assertStringContainsString('data-test-card', $html);
        $this->assertStringContainsString('Somente PHP e Blade', $html);
        $this->assertStringContainsString('color:#111111', $html);
        $this->assertSame(0, $edition->canvasElements()->count());
    }
    public function test_declared_media_collections_drive_native_picker_upload_constraints_and_rendered_references(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        $plugin = Filament::getCurrentPanel()->getPlugin('filament-canvas');
        $plugin->element(new InspectorMediaElement())->documentElements('test-document', ['test-card', 'test-media']);
        $context = new EditorContext(new DocumentReference('test-document', $edition->id), auth()->user(), 'canvas-dynamic-inspector-test');
        $library = new TestAssetLibrary();
        $png = $library->upload($context, UploadedFile::fake()->image('Capa.png'));
        $jpeg = $library->upload($context, UploadedFile::fake()->image('Foto.jpg'));
        $block = $this->dynamicElementInspectorBlock('Mídias');
        $block['type'] = 'test-media';
        $block['data']['dados'] = [];
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
        $editor->call('renderElement', $block)->assertHasNoErrors();
        $this->assertStringContainsString($png['image'], $editor->effects['returns'][0]);
    }
    public function test_upload_rpc_enforces_the_selected_collections_mime_size_and_file_count(): void
    {
        $edition = $this->dynamicElementInspectorPrepareEditor();
        Filament::getCurrentPanel()->getPlugin('filament-canvas')->element(new InspectorMediaElement())->documentElements('test-document', ['test-card', 'test-media']);
        config()->set('livewire.temporary_file_upload.disk', 'canvas_temporary_uploads');
        Storage::fake('canvas_temporary_uploads');
        $block = $this->dynamicElementInspectorBlock('Mídias');
        $block['type'] = 'test-media';
        $block['data']['dados'] = [];
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
        $this->dynamicElementInspectorEditor($edition)->call('renderElement', ['type' => 'test-card', 'data' => ['dados' => 'invalid']])->assertHasErrors('data.dados');
    }
    private function dynamicElementInspectorPrepareEditor(): TestDocument
    {
        $this->useCanvasFixtures();
        Gate::before(fn() => true);
        $panel = $this->canvasPanel('canvas-dynamic-inspector-test');
        $panel->getPlugin('filament-canvas')->element(new InspectorCardElement())->documentElements('test-document', ['test-card'])->editorView('test-document', 'filament-canvas::editor');
        $this->dynamicElementInspectorViewDirectory = sys_get_temp_dir() . '/canvas-inspector-' . bin2hex(random_bytes(8));
        mkdir($this->dynamicElementInspectorViewDirectory);
        file_put_contents($this->dynamicElementInspectorViewDirectory . '/card.blade.php', '<div data-test-card style="color:{{ $dados[\'preferences\'][\'color\'] }}">{{ $dados[\'title\'] ?? \'\' }}</div>');
        file_put_contents($this->dynamicElementInspectorViewDirectory . '/media.blade.php', '<img src="{{ $media[\'cover\'][\'image\'] ?? \'\' }}">@foreach ($media[\'gallery\'] ?? [] as $asset)<img src="{{ $asset[\'image\'] }}">@endforeach');
        $this->app['view']->addNamespace('canvas-inspector-tests', $this->dynamicElementInspectorViewDirectory);
        return TestDocument::create();
    }
    private function dynamicElementInspectorEditor(TestDocument $edition): Testable
    {
        return Livewire::test(CanvasEditor::class, ['documentType' => 'test-document', 'documentId' => $edition->id, 'panelId' => 'canvas-dynamic-inspector-test']);
    }
    private function dynamicElementInspectorBlock(string $name): array
    {
        return ['type' => 'test-card', 'data' => ['nome' => $name, 'x_pt' => 10, 'y_pt' => 10, 'largura_pt' => 200, 'altura_pt' => 40, 'rotacao_graus' => 0, 'visivel' => true,  'dados' => ['title' => null, 'enabled' => false, 'count' => 0, 'preferences' => ['color' => '#111111', 'row_slots' => [[1, 3], [2]]], 'internal' => ['source' => 'imported']]]];
    }
    public function test_generic_canvas_uses_host_contracts_for_load_preview_assets_and_save(): void
    {
        $user = new GenericUser(['id' => 12]);
        auth()->setUser($user);
        $blocks = [['type' => 'text', 'data' => $this->filamentPackagesElementData()]];
        unset($blocks[0]['data']['tipo'], $blocks[0]['data']['camada']);
        $canonical = ElementData::canonicalizeBlocks($blocks);
        $canonical[0]['data']['dados'] += ['rich_text' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => 'Texto original']]]]], 'html' => '<p style="text-align: center;">Texto original</p>'];
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
        $store->shouldReceive('save')->once()->withArgs(fn(EditorContext $context, array $payload, string $revision) => $payload === $canonical && $revision === 'revision-1')->andReturn(new SaveResult($saved, 'revision-2'));
        $renderer = Mockery::mock(PreviewRenderer::class);
        $renderer->shouldReceive('render')->andReturnUsing(fn(CanvasDocument $document) => '<div class="page">' . count($document->blocks) . '</div>');
        $library = Mockery::mock(AssetLibrary::class);
        $library->shouldReceive('assets')->once()->andReturn([['id' => 'asset-1', 'name' => 'Imagem', 'image' => '/media/example.webp']]);
        app(PanelRegistry::class)->register(Panel::make()->id('canvas-test')->plugin(CanvasPlugin::make()->documentStore('generic', $store)->previewRenderer('generic', $renderer)->assetLibrary('generic', $library)));
        $editor = new CanvasEditor();
        $editor->mount('generic', 'document-1', 'canvas-test');
        $this->assertSame('revision-1', $editor->revision);
        $this->assertSame('<div class="page">1</div>', $editor->preview($blocks));
        $this->assertSame($saved, $editor->salvar($blocks));
        $this->assertSame('revision-2', $editor->revision);
        $view = $editor->render()->getData();
        $this->assertSame($saved, $view['initial']);
        $this->assertSame('Canvas genérico', $view['metadata']['documentTitle']);
        $this->assertSame('asset-1', $view['assets'][0]['id']);
    }
    public function test_generic_canvas_rejects_out_of_bounds_elements_before_the_host_store_saves(): void
    {
        $store = Mockery::mock(DocumentStore::class);
        $store->shouldReceive('authorize');
        $store->shouldReceive('load')->andReturn(new CanvasDocument([], 100, 100, 'revision-1'));
        $store->shouldNotReceive('save');
        app(PanelRegistry::class)->register(Panel::make()->id('canvas-test')->plugin(CanvasPlugin::make()->documentStore('generic', $store)));
        $editor = new CanvasEditor();
        $editor->mount('generic', 'document-1', 'canvas-test');
        $data = $this->filamentPackagesElementData();
        unset($data['tipo'], $data['camada']);
        $data['largura_pt'] = 90;
        $data['altura_pt'] = 50;
        $this->expectException(ValidationException::class);
        $editor->salvar([['type' => 'text', 'data' => $data]]);
    }
    private function filamentPackagesElementData(): array
    {
        return ['nome' => 'Texto', 'tipo' => 'text', 'x_pt' => 20, 'y_pt' => 20, 'largura_pt' => 200, 'altura_pt' => 100, 'rotacao_graus' => 0, 'camada' => 0, 'visivel' => true, 'conteudo' => ['text' => 'Texto original'], 'estilo' => [], 'configuracoes' => [], 'vinculos' => []];
    }
}
class InspectorCardElement extends CanvasElement
{
    public function type(): string
    {
        return 'test-card';
    }
    public function label(): string
    {
        return 'Cartão';
    }
    public function view(): string
    {
        return 'canvas-inspector-tests::card';
    }
    public function properties(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->label('Texto do cartão')->default('Novo cartão')->maxLength(100), Toggle::make('enabled')->label('Habilitado')->default(false), TextInput::make('count')->label('Contagem')->numeric()->default(0), ColorPicker::make('preferences.color')->label('Cor do cartão')->default('#111111')]);
    }
    public function validationRules(string $prefix = ''): array
    {
        return [...parent::validationRules($prefix), $prefix . 'title' => ['nullable', 'string', 'max:100'], $prefix . 'enabled' => ['boolean'], $prefix . 'count' => ['numeric'], $prefix . 'preferences' => ['array'], $prefix . 'preferences.color' => ['string'], $prefix . 'internal' => ['array']];
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
