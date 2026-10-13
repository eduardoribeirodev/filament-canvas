<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Contracts\AssetLibrary;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Elements\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DataTableElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DrawingElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Examples\JsonDocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Rendering\CanvasRenderer;
use EduardoRibeiroDev\FilamentCanvas\Support\BlockValidator;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementValidation;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
class ElementTypesTest extends TestCase
{
    public function test_legacy_conversion_preserves_render_precedence_and_core_references(): void
    {
        $block = ['type' => 'text', 'data' => $this->elementApiCore() + ['imagem_origem' => 42, 'vinculos' => [17], 'configuracoes' => ['font_size' => 10, 'bold' => true, 'color' => '#333333'], 'conteudo' => ['text' => 'Original', 'font_size' => 12], 'estilo' => ['font_size' => 16, 'bold' => false, 'color' => '', 'opacity' => 0]]];
        $canonical = ElementData::canonicalizeBlock($block);
        $this->assertSame(['font_size' => 16, 'bold' => false, 'color' => '#333333', 'text' => 'Original', 'opacity' => 0], $canonical['data']['dados']);
        $this->assertSame(42, $canonical['data']['imagem_origem']);
        $this->assertSame([17], $canonical['data']['vinculos']);
        $this->assertSame(200, $canonical['data']['largura_pt']);
        $this->assertArrayNotHasKey('conteudo', $canonical['data']);
        $this->assertSame($canonical, ElementData::canonicalizeBlock($canonical));
        $this->assertSame([], ElementData::from(['dados' => [], 'conteudo' => ['text' => 'Ignored']]));
    }
    public function test_native_schema_defaults_seed_creation_and_existing_false_and_zero_survive(): void
    {
        $element = new CanvasSchemaBadge();
        $component = new CanvasSchemaHost();
        $schema = $element->properties(Schema::make($component))->statePath('dados');
        $schema->fill();
        $this->assertSame(['text' => 'Novo selo', 'enabled' => true, 'offset' => 4.0], $component->dados);
        $schema->fill(['text' => '', 'enabled' => false, 'offset' => 0]);
        $this->assertSame(['text' => '', 'enabled' => false, 'offset' => 0.0], $component->dados);
        $this->assertFalse(method_exists($element, 'defaults'));
    }
    public function test_builtin_schemas_use_field_defaults_without_a_separate_map(): void
    {
        foreach ([new TextElement(), new DrawingElement(), new DataTableElement(), new ImageElement()] as $element) {
            $component = new CanvasSchemaHost();
            $schema = $element->properties(Schema::make($component))->statePath('dados');
            $schema->fill();
            $state = ['dados' => $schema->getRawState()];
            $schema->dehydrateState($state);
            $schema->mutateDehydratedState($state);
            $dados = $element->normalize($state['dados']);
            app(BlockValidator::class)->validate([['type' => $element->type(), 'data' => $this->elementApiCore() + ['dados' => $dados]]], new CanvasDocument([], 300, 200), ElementRegistry::defaults());
            $this->assertNotEmpty($dados);
        }
    }
    public function test_reusable_text_schema_hydrates_plain_text_and_html_before_an_unrelated_edit(): void
    {
        foreach ([['text' => 'Texto original'], ['html' => '<p><strong>Texto formatado</strong></p>']] as $content) {
            $element = new TextElement();
            $component = new CanvasSchemaHost();
            $schema = $element->properties(Schema::make($component))->statePath('dados');
            $schema->fill($content + ['font_size' => 14]);
            $state = ['dados' => $schema->getRawState()];
            $schema->dehydrateState($state);
            $schema->mutateDehydratedState($state);
            $dados = $element->normalize($state['dados']);
            $this->assertStringContainsString($content['text'] ?? 'Texto formatado', $dados['text']);
            if (isset($content['html'])) {
                $this->assertStringContainsString('<strong>', $dados['html']);
            }
        }
    }
    public function test_programmatic_plain_state_keeps_text_but_an_explicit_empty_document_clears_it(): void
    {
        $element = new TextElement();
        $component = new CanvasSchemaHost();
        $schema = $element->properties(Schema::make($component))->statePath('dados');
        $schema->fill(['text' => 'Texto original']);
        $component->dados = ['text' => 'Texto original'];
        $state = ['dados' => $schema->getRawState()];
        $schema->callBeforeStateDehydrated($state);
        $schema->dehydrateState($state);
        $this->assertSame('Texto original', $element->normalize($state['dados'])['text']);
        $component->dados = ['text' => 'Texto original', 'rich_text' => ['type' => 'doc', 'content' => []]];
        $state = ['dados' => $schema->getRawState()];
        $schema->callBeforeStateDehydrated($state);
        $schema->dehydrateState($state);
        $this->assertSame('', $element->normalize($state['dados'])['text']);
    }
    public function test_reopening_an_explicitly_empty_rich_document_does_not_restore_stale_text(): void
    {
        $element = new TextElement();
        $component = new CanvasSchemaHost();
        $schema = $element->properties(Schema::make($component))->statePath('dados');
        $schema->fill(['text' => 'Texto antigo', 'html' => '<p>Texto antigo</p>', 'rich_text' => ['type' => 'doc', 'content' => []]]);
        $state = ['dados' => $schema->getRawState()];
        $schema->callBeforeStateDehydrated($state);
        $schema->dehydrateState($state);
        $schema->mutateDehydratedState($state);
        $this->assertSame('', $element->normalize($state['dados'])['text']);
        $this->assertStringNotContainsString('Texto antigo', $element->normalize($state['dados'])['html']);
    }
    public function test_custom_type_registers_its_schema_view_icon_and_document_scope(): void
    {
        $plugin = CanvasPlugin::make()->element(CanvasSchemaBadge::class)->documentElements('labels', ['badge']);
        $registry = $plugin->elements('labels');
        $this->assertSame(['badge'], $registry->types());
        $this->assertSame('heroicon-o-tag', $registry->metadataForEditor()['badge']['icon']);
        $this->assertSame('Selo', $registry->labels()['badge']);
        $dados = ['text' => 'Personalizado', 'enabled' => false, 'offset' => 0];
        $block = ['type' => 'badge', 'data' => $this->elementApiCore() + ['dados' => $dados]];
        app(BlockValidator::class)->validate([$block], new CanvasDocument([], 300, 200), $registry);
        $html = (new CanvasRenderer($registry))->render(new CanvasDocument([$block], 300, 200), $this->elementApiContext());
        $this->assertStringContainsString('Personalizado', $html);
        $this->assertStringContainsString('data-kind="badge"', $html);
        $this->assertContains('text', $plugin->elements()->types());
    }
    public function test_properties_not_declared_by_the_type_and_invalid_canonical_data_are_rejected(): void
    {
        foreach ([['text' => 'Valid', 'rows' => []], 'invalid'] as $dados) {
            try {
                app(BlockValidator::class)->validate([['type' => 'text', 'data' => $this->elementApiCore() + ['dados' => $dados]]], new CanvasDocument([], 300, 200), ElementRegistry::defaults());
                $this->fail('Unexpected properties and invalid containers must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
    public function test_media_capability_works_for_a_type_that_does_not_extend_image_element(): void
    {
        $library = new class implements AssetLibrary
        {
            public function assets(EditorContext $context): array
            {
                return [['id' => 42, 'name' => 'Autorizada', 'image' => '/media/authorized.webp']];
            }
        };
        $element = new CanvasCustomMediaElement();
        $registry = (new ElementRegistry())->register($element);
        $block = ['type' => 'custom-media', 'data' => $this->elementApiCore() + ['imagem_origem' => 42, 'dados' => ['fit' => 'contain', 'flip_x' => true]]];
        $html = (new CanvasRenderer($registry, $library))->render(new CanvasDocument([$block], 300, 200), $this->elementApiContext());
        $this->assertStringContainsString('/media/authorized.webp', $html);
        $this->assertStringContainsString('scale(-1,1)', $html);
        $this->assertSame('imagem_origem', $registry->metadataForEditor()['custom-media']['media_collections']['image']['reference_field']);
        $this->assertFalse($element instanceof ImageElement);
    }
    public function test_declared_media_collections_resolve_authorized_assets_and_validate_reference_limits(): void
    {
        $library = new class implements AssetLibrary
        {
            public function assets(EditorContext $context): array
            {
                return [['id' => 1, 'name' => 'Principal', 'image' => '/media/one.webp?x=1&y=2'], ['id' => 2, 'name' => 'Outra', 'image' => '/media/two.webp']];
            }
        };
        $element = new CanvasMultiMediaElement();
        $registry = (new ElementRegistry())->register($element);
        $block = ['type' => 'gallery', 'data' => $this->elementApiCore() + ['imagem_origem' => 1, 'gallery_ids' => [2, 99], 'dados' => []]];
        app(BlockValidator::class)->validate([$block], new CanvasDocument([], 300, 200), $registry);
        $media = $element->withAssetLibrary($library)->resolveMedia($block, $this->elementApiContext());
        $this->assertSame(1, $media['image']['id']);
        $this->assertSame([2], array_column($media['gallery'], 'id'));
        $html = (new CanvasRenderer($registry, $library))->render(new CanvasDocument([$block], 300, 200), $this->elementApiContext());
        $this->assertStringContainsString('/media/one.webp?x=1&amp;y=2', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        foreach ([[1, 2, 3], [['malformed']]] as $references) {
            $block['data']['gallery_ids'] = $references;
            try {
                app(BlockValidator::class)->validate([$block], new CanvasDocument([], 300, 200), $registry);
                $this->fail('Collection limits and scalar IDs must be validated.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
    public function test_a_render_override_disables_builtin_local_rendering_even_with_the_default_view(): void
    {
        $registry = ElementRegistry::defaults()->register(new CanvasWrappedText());
        $this->assertFalse($registry->metadataForEditor()['text']['local_renderer']);
        $this->assertSame('text', $registry->metadataForEditor()['title']['local_renderer']);
        $optIn = new class extends CanvasWrappedText
        {
            public function capabilities(): array
            {
                return [...parent::capabilities(), 'local_renderer' => 'text'];
            }
        };
        $registry->register($optIn);
        $this->assertSame('text', $registry->metadataForEditor()['text']['local_renderer']);
    }
    public function test_json_example_loads_legacy_and_saves_canonical_with_stale_revision_protection(): void
    {
        $context = new EditorContext(new DocumentReference('test-api', uniqid()), (new User())->forceFill(['id' => 7]));
        Gate::define('canvas-document', fn($user) => $user->getAuthIdentifier() === 7);
        $path = storage_path('app/canvas/' . hash('sha256', $context->reference->type . ':' . $context->reference->id) . '.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['blocks' => [['type' => 'text', 'data' => $this->elementApiCore() + ['conteudo' => ['text' => 'Antigo'], 'estilo' => ['font_size' => 14]]]]]));
        try {
            $store = new JsonDocumentStore();
            $document = $store->load($context);
            $this->assertSame(['text' => 'Antigo', 'font_size' => 14], $document->blocks[0]['data']['dados']);
            $changed = $document->blocks;
            $changed[0]['data']['dados']['text'] = 'Novo';
            $saved = $store->save($context, $changed, $document->revision);
            $this->assertNotSame($document->revision, $saved->revision);
            $this->assertSame($saved->revision, $store->load($context)->revision);
            $this->assertStringNotContainsString('conteudo', File::get($path));
            $this->expectException(ValidationException::class);
            $store->save($context, $document->blocks, $document->revision);
        } finally {
            File::delete([$path, $path . '.lock']);
        }
    }
    private function elementApiCore(): array
    {
        return ['nome' => 'Elemento', 'x_pt' => 20, 'y_pt' => 20, 'largura_pt' => 200, 'altura_pt' => 100, 'rotacao_graus' => 0, 'visivel' => true];
    }
    private function elementApiContext(): EditorContext
    {
        return new EditorContext(new DocumentReference('test-api', 1));
    }
    private function dataTableSchema(array $data): Schema
    {
        $host = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;
            public array $properties = [];
        };
        $schema = (new DataTableElement())->properties(Schema::make($host)->statePath('properties'));
        $schema->fill($data);
        return $schema;
    }
    public function test_rows_use_column_titles_and_header_renaming_keeps_existing_cell_keys(): void
    {
        $data = ['columns' => [['label' => 'Nome', 'field' => 'name'], ['label' => 'Código', 'field' => 'sku']], 'rows' => [['name' => 'Conector', 'sku' => 'AT-01']], 'font_size' => 12];
        $schema = $this->dataTableSchema($data);
        $rows = $schema->getComponentByStatePath('rows');
        $child = array_values($rows->getChildSchemas())[0];
        $this->assertSame(['Nome', 'Código'], array_map(fn($field) => $field->getLabel(), $child->getComponents()));
        $this->assertSame($data['rows'], $schema->getState()['rows']);
        $data['columns'][0]['label'] = 'Descrição';
        $this->assertSame($data['rows'], $this->dataTableSchema($data)->getState()['rows']);
        ElementValidation::validate(new DataTableElement(), $data);
    }
    public function test_new_column_keys_are_hidden_and_generated_by_the_schema(): void
    {
        $schema = $this->dataTableSchema(['columns' => [['label' => 'Quantidade']], 'rows' => []]);
        $columns = $schema->getComponentByStatePath('columns');
        $child = array_values($columns->getChildSchemas())[0];
        $field = $child->getComponentByStatePath('field');
        $this->assertInstanceOf(Hidden::class, $field);
        $this->assertMatchesRegularExpression('/^column_[a-z0-9]{12}$/', $schema->getState()['columns'][0]['field']);
    }
    public function test_tables_include_borders_and_header_style_without_requiring_a_template(): void
    {
        $html = (new DataTableElement())->render(['type' => 'data-table', 'data' => ['dados' => ['columns' => [['label' => 'Nome', 'field' => 'name']], 'rows' => [['name' => '<script>']]]]], new EditorContext(new DocumentReference('qa', 1)));
        $this->assertStringContainsString('border-collapse:collapse', $html);
        $this->assertStringContainsString('border:1px solid #d1d5db', $html);
        $this->assertStringContainsString('scope="col"', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
    public function test_duplicate_column_keys_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        ElementValidation::validate(new DataTableElement(), ['columns' => [['label' => 'A', 'field' => 'same'], ['label' => 'B', 'field' => 'same']], 'rows' => []]);
    }
    public function test_schema_only_rules_validate_nested_fields_conditions_and_choices_without_inserting_defaults(): void
    {
        $element = new SchemaOnlyCanvasElement();
        $dados = ['title' => 'Válido', 'enabled' => false, 'items' => [['label' => 'Item', 'kind' => 'text']]];
        ElementValidation::validate($element, $dados);
        $this->assertArrayNotHasKey('note', $dados);
        $this->assertArrayNotHasKey('offset', $dados);
        foreach ([['title' => str_repeat('x', 9)], ['enabled' => 'yes'], ['enabled' => true], ['note' => str_repeat('x', 9)], ['advanced' => str_repeat('x', 9)], ['items' => [['label' => '', 'kind' => 'text']]], ['items' => [['label' => 'Item', 'kind' => 'unknown']]]] as $patch) {
            $invalid = array_replace($dados, $patch);
            $prefix = 'blocks.2.data.dados.';
            $rules = ElementValidation::rules($element, $invalid, $prefix);
            $payload = [];
            data_set($payload, rtrim($prefix, '.'), $invalid);
            $validator = Validator::make($payload, $rules);
            $this->assertTrue($validator->fails(), json_encode($patch));
            foreach (array_keys($validator->errors()->toArray()) as $path) {
                $this->assertStringStartsWith($prefix, $path);
            }
        }
    }
    public function test_invalid_repeater_shapes_report_validation_errors_instead_of_schema_runtime_errors(): void
    {
        foreach (['invalid', [null], [['label' => 'Item'], false], [['label' => 'Item', 'kind' => ['text']]], array_fill(0, 4, ['label' => 'Item'])] as $items) {
            try {
                ElementValidation::validate(new SchemaOnlyCanvasElement(), ['title' => 'Válido', 'items' => $items]);
                $this->fail('Malformed repeater input must fail validation.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
                $this->assertStringStartsWith('items', array_key_first($exception->errors()));
            }
        }
    }
}
class CanvasSchemaHost extends Component implements HasSchemas
{
    use InteractsWithSchemas;
    public array $dados = [];
}
class CanvasSchemaBadge extends CanvasElement
{
    public function type(): string
    {
        return 'badge';
    }
    public function label(): string
    {
        return 'Selo';
    }
    public function icon(): string
    {
        return 'heroicon-o-tag';
    }
    public function view(): string
    {
        return 'filament-canvas::elements.text';
    }
    public function properties(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('text')->default('Novo selo'), Toggle::make('enabled')->default(true), TextInput::make('offset')->numeric()->default(4)]);
    }
    public function validationRules(string $prefix = ''): array
    {
        return [$prefix . 'text' => ['string'], $prefix . 'enabled' => ['boolean'], $prefix . 'offset' => ['numeric']];
    }
}
class CanvasCustomMediaElement extends CanvasElement implements HasElementMedia
{
    private ?AssetLibrary $assets = null;
    public function type(): string
    {
        return 'custom-media';
    }
    public function label(): string
    {
        return 'Mídia própria';
    }
    public function view(): string
    {
        return 'filament-canvas::elements.image';
    }
    public function capabilities(): array
    {
        return ['image' => true];
    }
    public function mediaCollections(): array
    {
        return ['image' => ['reference_field' => 'imagem_origem', 'max_files' => 1, 'max_size' => 200]];
    }
    public function withAssetLibrary(AssetLibrary $library): static
    {
        $copy = clone $this;
        $copy->assets = $library;
        return $copy;
    }
    public function resolveMedia(array $block, EditorContext $context): array
    {
        return ['image' => $this->assets->assets($context)[0]];
    }
}
class CanvasMultiMediaElement extends ImageElement
{
    public function __construct()
    {
        parent::__construct('gallery');
    }
    public function mediaCollections(): array
    {
        return [...parent::mediaCollections(), 'gallery' => ['reference_field' => 'gallery_ids', 'max_files' => 2, 'max_size' => 200]];
    }
}
class CanvasWrappedText extends TextElement
{
    public function render(array $block, EditorContext $context): string
    {
        return '<aside data-custom-render>' . parent::render($block, $context) . '</aside>';
    }
}
class SchemaOnlyCanvasElement extends CanvasElement
{
    public function type(): string
    {
        return 'schema-only';
    }
    public function label(): string
    {
        return 'Exemplo';
    }
    public function view(): string
    {
        return 'filament-canvas::elements.text';
    }
    public function properties(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->rules(['string'])->required()->maxLength(8), Toggle::make('enabled')->default(false)->live(), TextInput::make('note')->rules(['string'])->required()->maxLength(8)->default('Inicial')->visible(fn($get) => $get('enabled')), TextInput::make('offset')->numeric()->default(4), Repeater::make('items')->maxItems(3)->schema([TextInput::make('label')->rules(['string'])->required()->maxLength(8), Select::make('kind')->options(['text' => 'Texto', 'image' => 'Imagem'])]), Section::make('Advanced')->visible(fn($get) => $get('enabled'))->schema([TextInput::make('advanced')->required()->rules(['string'])->maxLength(8)])]);
    }
}
