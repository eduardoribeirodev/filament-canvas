<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasRenderData;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TitleElement;
use EduardoRibeiroDev\FilamentCanvas\Livewire\CanvasEditor;
use EduardoRibeiroDev\FilamentCanvas\Models\Concerns\HasCanvas;
use EduardoRibeiroDev\FilamentCanvas\Pages\EditCanvas;
use EduardoRibeiroDev\FilamentCanvas\Schemas\Components\Canvas;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema as SchemasSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
class TemplatesTest extends TestCase
{
    private bool $templatesAllowAccess = true;
    private string $templatesViews;
    private CanvasTemplateDocument $templatesOwner;
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Schema::create('canvas_template_documents', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        (require dirname(__DIR__, 2).'/database/migrations/2026_10_10_020000_create_canvas_elements_table.php')->up();
        $this->createMediaTable();
        auth()->setUser((new User())->forceFill(['id' => 1, 'name' => 'Editor']));
        Gate::before(fn() => $this->templatesAllowAccess);
        $this->templatesOwner = CanvasTemplateDocument::create();
        $this->templatesViews = sys_get_temp_dir() . '/canvas-template-tests-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->templatesViews);
        File::put($this->templatesViews . '/panel.blade.php', '<section class="page" data-template="panel">{!! $blocks ?? "" !!}</section>');
        File::put($this->templatesViews . '/page.blade.php', '<section class="page" data-template="page" data-note="{{ $data["note"] ?? "" }}">{!! $blocks ?? "" !!}</section>');
        view()->addNamespace('canvas-test', $this->templatesViews);
    }
    protected function tearDown(): void
    {
        File::deleteDirectory($this->templatesViews);
        parent::tearDown();
    }
    public function test_resource_page_uses_its_model_without_document_mapping_and_the_default_white_template(): void
    {
        $plugin = CanvasPlugin::make();
        $this->templatesPanel($plugin);
        Livewire::test(CanvasTemplatePage::class, ['record' => $this->templatesOwner->id])->assertOk()->assertSeeLivewire(CanvasEditor::class);
        $parameters = CanvasTemplatePage::editorParameters($this->templatesOwner->id);
        $this->assertSame(CanvasTemplateDocument::class, $parameters['documentType']);
        $editor = Livewire::test(CanvasEditor::class, $parameters);
        $html = $editor->instance()->render()->getData()['previewHtml'];
        $this->assertStringContainsString('background:#fff', $html);
        $editor->call('save', [$this->templatesBlock()])->assertHasNoErrors();
        $this->assertSame('Texto salvo', $this->templatesOwner->canvasElements()->first()->dados['text']);
        Livewire::test(CanvasEditor::class, CanvasTemplatePage::editorParameters($this->templatesOwner->id))->assertOk()->call('preview', [$this->templatesBlock()])->assertHasNoErrors();
        $this->assertSame('filament-canvas::templates.blank', $plugin->getTemplate());
    }
    public function test_page_template_and_element_list_override_panel_defaults_without_changing_another_page(): void
    {
        $plugin = CanvasPlugin::make()->template('canvas-test::panel')->elements([TitleElement::class]);
        $this->templatesPanel($plugin);
        $editor = Livewire::test(CanvasEditor::class, CanvasTemplateOverridePage::editorParameters($this->templatesOwner->id));
        $view = $editor->instance()->render()->getData();
        $this->assertSame(['text', 'notice'], array_keys($view['elementTypes']));
        $this->assertStringContainsString('data-template="page"', $view['previewHtml']);
        $block = $this->templatesBlock('notice');
        $block['data']['dados']['badge'] = 'AK';
        $editor->call('save', [$block])->assertHasNoErrors();
        $this->assertSame('AK', $this->templatesOwner->canvasElements()->first()->dados['badge']);
        $editor->call('renderElement', $block)->assertHasNoErrors();
        Livewire::test(CanvasEditor::class, CanvasTemplateOverridePage::editorParameters($this->templatesOwner->id))->assertOk();
        $this->assertNotContains('notice', ElementRegistry::forOwner($this->templatesOwner)->types());
        $other = CanvasTemplateDocument::create();
        $view = Livewire::test(CanvasEditor::class, CanvasTemplatePage::editorParameters($other->id))->instance()->render()->getData();
        $this->assertSame(['title'], array_keys($view['elementTypes']));
        $this->assertStringContainsString('data-template="panel"', $view['previewHtml']);
        $this->assertSame('canvas-test::panel', $plugin->getTemplate());
    }
    public function test_panel_extra_elements_are_added_to_defaults_and_native_rules_apply_during_save(): void
    {
        $this->templatesPanel(CanvasPlugin::make()->extraElements([CanvasTemplateNotice::class]));
        $editor = Livewire::test(CanvasEditor::class, CanvasTemplatePage::editorParameters($this->templatesOwner->id));
        $types = array_keys($editor->instance()->render()->getData()['elementTypes']);
        $this->assertContains('text', $types);
        $this->assertContains('notice', $types);
        $invalid = $this->templatesBlock('notice');
        $editor->call('save', [$invalid])->assertHasErrors(['blocks.0.data.dados.badge' => 'required']);
        $this->assertSame(0, $this->templatesOwner->canvasElements()->count());
        $invalid['data']['dados']['badge'] = 'Too long to save';
        $editor->call('save', [$invalid])->assertHasErrors(['blocks.0.data.dados.badge' => 'max']);
        $valid = $this->templatesBlock('notice');
        $valid['data']['dados']['badge'] = 'AK';
        $editor->call('save', [$valid])->assertHasNoErrors();
        $this->assertSame('notice', $this->templatesOwner->canvasElements()->first()->tipo);
    }
    public function test_schema_can_bind_an_owner_and_preserve_its_options_across_livewire_requests(): void
    {
        $this->templatesPanel(CanvasPlugin::make()->template('canvas-test::panel'));
        $parameters = Canvas::make()->owner(CanvasTemplateDocument::class, $this->templatesOwner->id)->template('canvas-test::page')->elements([TextElement::class])->getEditorParameters();
        $editor = Livewire::test(CanvasEditor::class, $parameters)->call('save', [$this->templatesBlock()])->assertHasNoErrors();
        $this->assertStringContainsString('data-template="page"', $editor->instance()->render()->getData()['previewHtml']);
        $this->assertStringContainsString('data-note="Texto salvo"', $editor->instance()->render()->getData()['previewHtml']);
    }
    public function test_server_configuration_cannot_be_tampered_with_or_reused_for_another_record(): void
    {
        $this->templatesPanel(CanvasPlugin::make());
        $parameters = CanvasTemplatePage::editorParameters($this->templatesOwner->id);
        $other = CanvasTemplateDocument::create();
        Livewire::test(CanvasEditor::class, array_replace($parameters, ['documentId' => $other->id]))->assertForbidden();
        Livewire::test(CanvasEditor::class, array_replace($parameters, ['configuration' => 'altered']))->assertForbidden();
        $this->templatesAllowAccess = false;
        Livewire::test(CanvasEditor::class, $parameters)->assertForbidden();
    }
    public function test_preview_and_export_share_the_same_template_and_differ_only_in_output_mode(): void
    {
        $this->templatesPanel(CanvasPlugin::make());
        $plugin = CanvasPlugin::make()->forModel('document', CanvasTemplateDocument::class, ['template' => 'canvas-test::page']);
        $context = new EditorContext(new DocumentReference('document', $this->templatesOwner->id), auth()->user());
        $document = new CanvasDocument([$this->templatesBlock()], 300, 200);
        $preview = $plugin->resolvePreviewRenderer('document')->render($document, $context);
        $output = $plugin->resolveRenderer('document')->renderOutput(['page_size' => [300, 200], 'note' => 'Texto salvo'], ['blocks' => '']);
        $this->assertStringContainsString('data-template="page" data-note="Texto salvo"', $preview);
        $this->assertStringContainsString('data-template="page" data-note="Texto salvo"', $output);
        $this->assertStringContainsString('zoom:.75', $preview);
        $this->assertStringNotContainsString('zoom:.75', $output);
        $this->assertStringContainsString('@page{size:300pt 200pt', $output);
    }
    private function templatesPanel(CanvasPlugin $plugin): void
    {
        $panel = Panel::make()->id('canvas-templates')->path('canvas-templates')->plugin($plugin)->resources([CanvasTemplateResource::class]);
        app(PanelRegistry::class)->register($panel);
        Route::prefix('canvas-templates')->name('filament.canvas-templates.')->group(fn() => CanvasTemplateResource::registerRoutes($panel));
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();
    }
    private function templatesBlock(string $type = 'text'): array
    {
        return ['type' => $type, 'data' => ['nome' => 'Texto', 'x_pt' => 10, 'y_pt' => 10, 'largura_pt' => 100, 'altura_pt' => 50, 'rotacao_graus' => 0, 'visivel' => true, 'dados' => ['text' => 'Texto salvo', 'font_size' => 16]]];
    }
}
class CanvasTemplateDocument extends Model implements CanvasOwner, CanvasRenderData
{
    use HasCanvas;
    protected $table = 'canvas_template_documents';
    protected $guarded = ['id'];
    public function canvasRenderData(CanvasDocument $document, EditorContext $context): array
    {
        return ['note' => $document->blocks[0]['data']['dados']['text'] ?? ''];
    }
}
class CanvasTemplateResource extends Resource
{
    protected static ?string $model = CanvasTemplateDocument::class;
    public static function getPages(): array
    {
        return ['index' => CanvasTemplatePage::route('/'), 'canvas' => CanvasTemplatePage::route('/{record}/canvas')];
    }
}
class CanvasTemplatePage extends EditCanvas
{
    protected static string $resource = CanvasTemplateResource::class;
}
class CanvasTemplateOverridePage extends CanvasTemplatePage
{
    protected static ?string $canvasTemplate = 'canvas-test::page';
    protected static ?array $canvasElements = [TextElement::class];
    protected static ?array $canvasExtraElements = [CanvasTemplateNotice::class];
}
class CanvasTemplateNotice extends TextElement
{
    public function type(): string
    {
        return 'notice';
    }
    public function properties(SchemasSchema $schema): SchemasSchema
    {
        return parent::properties($schema)->components([...parent::properties(SchemasSchema::make())->getComponents(), TextInput::make('badge')->required()->maxLength(8)->default('AK')]);
    }
}
