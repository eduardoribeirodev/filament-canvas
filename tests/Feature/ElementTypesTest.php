<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
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
use EduardoRibeiroDev\FilamentCanvas\Rendering\ElementMarkup;
use EduardoRibeiroDev\FilamentCanvas\Rendering\ElementViewData;
use EduardoRibeiroDev\FilamentCanvas\Support\BlockValidator;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementValidation;
use EduardoRibeiroDev\FilamentCanvas\Support\MediaLibrary;
use EduardoRibeiroDev\FilamentCanvas\Tests\Support\TestDocument;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ElementTypesTest extends TestCase
{
    public function test_saved_image_urls_cannot_bypass_server_media_preparation(): void
    {
        $element = new ImageElement;
        $block = ['type' => 'asset', 'data' => $this->elementApiColore() + ['properties' => ['image_url' => 'file:///private/image.png']]];
        $data = app(ElementViewData::class)->prepare($element, $block, $this->elementApiContext());

        $this->assertSame('', $data['image_url']);
        $this->assertSame('', $data['media']['image']['image']);
    }

    public function test_output_values_are_embedded_as_json_without_injecting_html(): void
    {
        $values = ['text' => '"</div><script>alert(1)</script>', 'enabled' => false, 'offset' => 0];
        $html = ElementMarkup::wrap('<span x-text="data.text"></span>', $values);
        preg_match('/data-canvas-values="([^"]+)"/', $html, $match);

        $this->assertSame($values, json_decode(html_entity_decode($match[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('x-text="data.text"', $html);
    }

    public function test_element_api_only_requires_identity_form_and_view(): void
    {
        $methods = array_map(fn ($method) => $method->name, (new \ReflectionClass(ElementType::class))->getMethods());
        $this->assertEqualsCanonicalizing(['type', 'getLabel', 'getIcon', 'form', 'view'], $methods);
        $element = new TextElement;
        $this->assertStringContainsString('x-html="data.html"', ElementMarkup::template($element));
        foreach (['capabilities', 'render', 'editorTemplate', 'compileEditorTemplate', 'resolveMedia', 'viewData', 'validationRules', 'typographyRules'] as $method) {
            $this->assertFalse(method_exists($element, $method), $method.' belongs outside the element API.');
        }
    }

    public function test_element_markup_carries_prepared_values_separately_from_its_blade_view(): void
    {
        $received = null;
        view()->composer('filament-canvas::elements.text', function ($view) use (&$received): void {
            $received = $view->getData();
        });
        $element = new TextElement;
        $preparer = new class extends ElementViewData
        {
            public function prepare(ElementType $element, array $block, EditorContext $context): array
            {
                return array_replace(parent::prepare($element, $block, $context), ['text' => 'Prepared <title>', 'enabled' => false, 'offset' => 0]);
            }
        };
        $block = ['type' => 'text', 'data' => $this->elementApiColore() + ['properties' => ['text' => 'Stored', 'rule' => false]]];
        $original = $block;
        $data = $preparer->prepare($element, $block, $this->elementApiContext());
        $html = ElementMarkup::wrap(ElementMarkup::template($element), $data);
        preg_match('/data-canvas-values="([^"]+)"/', $html, $match);

        $this->assertArrayNotHasKey('text', $received);
        $this->assertArrayNotHasKey('geometry', $received);
        $this->assertArrayNotHasKey('data', $received);
        $this->assertArrayNotHasKey('block', $received);
        $this->assertArrayNotHasKey('context', $received);
        $data = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Prepared <title>', $data['text']);
        $this->assertFalse($data['enabled']);
        $this->assertFalse($data['rule']);
        $this->assertSame(0, $data['offset']);
        $this->assertSame((float) $block['data']['width_pt'], (float) $data['geometry']['width']);
        $this->assertSame('text', $data['type']);
        $this->assertSame([], $data['media']);
        $this->assertStringContainsString('x-html="data.html"', $html);
        $this->assertSame($original, $block);
    }

    public function test_old_element_envelopes_are_rejected_instead_of_converted(): void
    {
        foreach ([['dados' => ['text' => 'Old']], ['content' => ['text' => 'Old']], ['style' => ['font_size' => 12]], ['settings' => []]] as $old) {
            try {
                app(BlockValidator::class)->validate([[
                    'type' => 'text', 'data' => $this->elementApiColore() + ['properties' => []] + $old,
                ]], new CanvasDocument([], 300, 200), ElementRegistry::defaults());
                $this->fail('An old envelope must not be accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('blocks.0.data', $exception->errors());
            }
        }
    }

    public function test_native_schema_defaults_seed_creation_and_existing_false_and_zero_survive(): void
    {
        $element = new CanvasSchemaBadge;
        $component = new CanvasSchemaHost;
        $schema = $element->form(Schema::make($component))->statePath('properties');
        $schema->fill();
        $this->assertSame(['text' => 'Novo selo', 'enabled' => true, 'offset' => 4.0], $component->properties);
        $schema->fill(['text' => '', 'enabled' => false, 'offset' => 0]);
        $this->assertSame(['text' => '', 'enabled' => false, 'offset' => 0.0], $component->properties);
        $this->assertFalse(method_exists($element, 'defaults'));
    }

    public function test_builtin_schemas_use_field_defaults_without_a_separate_map(): void
    {
        foreach ([new TextElement, new DrawingElement, new DataTableElement, new ImageElement] as $element) {
            $component = new CanvasSchemaHost;
            $schema = $element->form(Schema::make($component))->statePath('properties');
            $schema->fill();
            $state = ['properties' => $schema->getRawState()];
            $schema->dehydrateState($state);
            $schema->mutateDehydratedState($state);
            $properties = $element->normalize($state['properties']);
            app(BlockValidator::class)->validate([['type' => $element->type(), 'data' => $this->elementApiColore() + ['properties' => $properties]]], new CanvasDocument([], 300, 200), ElementRegistry::defaults());
            $this->assertNotEmpty($properties);
        }
    }

    public function test_reopening_an_explicitly_empty_rich_document_does_not_restore_stale_text(): void
    {
        $element = new TextElement;
        $component = new CanvasSchemaHost;
        $schema = $element->form(Schema::make($component))->statePath('properties');
        $schema->fill(['text' => 'Text antigo', 'html' => '<p>Text antigo</p>', 'rich_text' => ['type' => 'doc', 'content' => []]]);
        $state = ['properties' => $schema->getRawState()];
        $schema->callBeforeStateDehydrated($state);
        $schema->dehydrateState($state);
        $schema->mutateDehydratedState($state);
        $this->assertSame('', $element->normalize($state['properties'])['text']);
        $this->assertStringNotContainsString('Text antigo', $element->normalize($state['properties'])['html']);
    }

    public function test_custom_type_registers_its_schema_view_icon_and_document_scope(): void
    {
        $plugin = CanvasPlugin::make()->element(CanvasSchemaBadge::class)->documentElements('labels', ['badge']);
        $registry = $plugin->elements('labels');
        $this->assertSame(['badge'], $registry->types());
        $this->assertSame('heroicon-o-tag', $registry->metadataForEditor()['badge']['icon']);
        $this->assertSame('Selo', $registry->labels()['badge']);
        $properties = ['text' => 'Personalizado', 'enabled' => false, 'offset' => 0];
        $block = ['type' => 'badge', 'data' => $this->elementApiColore() + ['properties' => $properties]];
        app(BlockValidator::class)->validate([$block], new CanvasDocument([], 300, 200), $registry);
        $html = (new CanvasRenderer($registry))->render(new CanvasDocument([$block], 300, 200), $this->elementApiContext());
        $this->assertStringContainsString('Personalizado', $html);
        $this->assertStringContainsString('data-kind="badge"', $html);
        $this->assertContains('text', $plugin->elements()->types());
    }

    public function test_properties_not_declared_by_the_type_and_invalid_canonical_data_are_rejected(): void
    {
        foreach ([['text' => 'Valid', 'rows' => []], 'invalid'] as $properties) {
            try {
                app(BlockValidator::class)->validate([['type' => 'text', 'data' => $this->elementApiColore() + ['properties' => $properties]]], new CanvasDocument([], 300, 200), ElementRegistry::defaults());
                $this->fail('Unexpected properties and invalid containers must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_media_capability_works_for_a_type_that_does_not_extend_image_element(): void
    {
        $this->useCanvasFixtures();
        Gate::before(fn () => true);
        $owner = TestDocument::create();
        $source = $owner->addMedia(UploadedFile::fake()->image('authorized.png'))->toMediaCollection('library');
        $context = (new EditorContext(new DocumentReference('test-document', $owner->id), auth()->user()))->forDocument($owner);
        $element = new CanvasCustomMediaElement;
        $registry = (new ElementRegistry)->register($element);
        $block = ['type' => 'custom-media', 'data' => $this->elementApiColore() + ['image_id' => $source->id, 'properties' => ['fit' => 'contain', 'flip_x' => true]]];
        $html = (new CanvasRenderer($registry))->render(new CanvasDocument([$block], 300, 200), $context);
        $this->assertStringContainsString($source->getUrl(), $html);
        $this->assertStringContainsString('&quot;flip_x&quot;:true', $html);
        $this->assertStringContainsString(':style="data.image_style"', $html);
        $this->assertSame('image_id', $registry->metadataForEditor()['custom-media']['media_collections']['image']['reference_field']);
        $this->assertFalse($element instanceof ImageElement);
    }

    public function test_declared_media_collections_resolve_authorized_assets_and_validate_reference_limits(): void
    {
        $this->useCanvasFixtures();
        Gate::before(fn () => true);
        $owner = TestDocument::create();
        $first = $owner->addMedia(UploadedFile::fake()->image('one.png'))->toMediaCollection('library');
        $second = $owner->addMedia(UploadedFile::fake()->image('two.png'))->toMediaCollection('library');
        $context = (new EditorContext(new DocumentReference('test-document', $owner->id), auth()->user()))->forDocument($owner);
        $element = new CanvasMultiMediaElement;
        $registry = (new ElementRegistry)->register($element);
        $block = ['type' => 'gallery', 'data' => $this->elementApiColore() + ['image_id' => 1, 'gallery_ids' => [2, 99], 'properties' => []]];
        app(BlockValidator::class)->validate([$block], new CanvasDocument([], 300, 200), $registry);
        $media = (new MediaLibrary($context))->resolve($element, $block);
        $this->assertSame(1, $media['image']['id']);
        $this->assertSame([2], array_column($media['gallery'], 'id'));
        $html = (new CanvasRenderer($registry))->render(new CanvasDocument([$block], 300, 200), $context);
        $this->assertStringContainsString($first->getUrl(), $html);
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

    public function test_registry_derives_behavior_and_templates_from_element_classes(): void
    {
        $registry = ElementRegistry::from([new CanvasSchemaBadge, new TextElement('notice'), new ImageElement('portrait')]);
        $metadata = $registry->metadataForEditor();
        $this->assertSame('text', $metadata['notice']['kind']);
        $this->assertSame('image', $metadata['portrait']['kind']);
        $this->assertSame('element', $metadata['badge']['kind']);
        $this->assertArrayNotHasKey('capabilities', $metadata['notice']);
        $this->assertFalse($metadata['portrait']['toolbar']);
        $this->assertTrue($metadata['badge']['toolbar']);
        $templates = $registry->templates();
        $this->assertStringContainsString('x-html="data.html"', $templates['badge']);
        $this->assertStringContainsString('class="block-link"', $templates['badge']);
    }

    public function test_json_example_preserves_current_properties_and_rejects_stale_revisions(): void
    {
        $context = new EditorContext(new DocumentReference('test-api', uniqid()), (new User)->forceFill(['id' => 7]));
        Gate::define('canvas-document', fn ($user) => $user->getAuthIdentifier() === 7);
        $path = storage_path('app/canvas/'.hash('sha256', $context->reference->type.':'.$context->reference->id).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['blocks' => [['type' => 'text', 'data' => $this->elementApiColore() + ['properties' => ['text' => 'Original', 'font_size' => 14]]]]]));
        try {
            $store = new JsonDocumentStore;
            $document = $store->load($context);
            $this->assertSame(['text' => 'Original', 'font_size' => 14], $document->blocks[0]['data']['properties']);
            $changed = $document->blocks;
            $changed[0]['data']['properties']['text'] = 'Novo';
            $saved = $store->save($context, $changed, $document->revision);
            $this->assertNotSame($document->revision, $saved->revision);
            $this->assertSame($saved->revision, $store->load($context)->revision);
            $this->assertStringNotContainsString('content', File::get($path));
            $this->expectException(ValidationException::class);
            $store->save($context, $document->blocks, $document->revision);
        } finally {
            File::delete([$path, $path.'.lock']);
        }
    }

    private function elementApiColore(): array
    {
        return ['name' => 'Element', 'x_pt' => 20, 'y_pt' => 20, 'width_pt' => 200, 'height_pt' => 100, 'rotation_deg' => 0, 'visible' => true];
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
        $schema = (new DataTableElement)->form(Schema::make($host)->statePath('properties'));
        $schema->fill($data);

        return $schema;
    }

    public function test_rows_use_column_titles_and_header_renaming_keeps_existing_cell_keys(): void
    {
        $data = ['columns' => [['label' => 'Name', 'field' => 'name'], ['label' => 'Código', 'field' => 'sku']], 'rows' => [['name' => 'Conector', 'sku' => 'AT-01']], 'font_size' => 12];
        $schema = $this->dataTableSchema($data);
        $rows = $schema->getComponentByStatePath('rows');
        $child = array_values($rows->getChildSchemas())[0];
        $this->assertSame(['Name', 'Código'], array_map(fn ($field) => $field->getLabel(), $child->getComponents()));
        $this->assertSame($data['rows'], $schema->getState()['rows']);
        $data['columns'][0]['label'] = 'Descrição';
        $this->assertSame($data['rows'], $this->dataTableSchema($data)->getState()['rows']);
        ElementValidation::validate(new DataTableElement, $data);
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
        $html = ElementMarkup::template(new DataTableElement);
        $this->assertStringContainsString('border-collapse:collapse', $html);
        $this->assertStringContainsString('border:1px solid #d1d5db', $html);
        $this->assertStringContainsString('scope="col"', $html);
        $this->assertStringContainsString("x-text=\"row[column.field] ?? ''\"", $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_duplicate_column_keys_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        ElementValidation::validate(new DataTableElement, ['columns' => [['label' => 'A', 'field' => 'same'], ['label' => 'B', 'field' => 'same']], 'rows' => []]);
    }

    public function test_schema_only_rules_validate_nested_fields_conditions_and_choices_without_inserting_defaults(): void
    {
        $element = new SchemaOnlyCanvasElement;
        $properties = ['title' => 'Válido', 'enabled' => false, 'items' => [['label' => 'Item', 'kind' => 'text']]];
        ElementValidation::validate($element, $properties);
        $this->assertArrayNotHasKey('note', $properties);
        $this->assertArrayNotHasKey('offset', $properties);
        foreach ([['title' => str_repeat('x', 9)], ['enabled' => 'yes'], ['enabled' => true], ['note' => str_repeat('x', 9)], ['advanced' => str_repeat('x', 9)], ['items' => [['label' => '', 'kind' => 'text']]], ['items' => [['label' => 'Item', 'kind' => 'unknown']]]] as $patch) {
            $invalid = array_replace($properties, $patch);
            $validator = Validator::make($invalid, ElementValidation::rules($element, $invalid));
            $this->assertTrue($validator->fails(), json_encode($patch));
            try {
                app(BlockValidator::class)->validate([
                    ['type' => $element->type(), 'data' => $this->elementApiColore() + ['properties' => $invalid]],
                ], new CanvasDocument([], 300, 200), ElementRegistry::from([$element]));
                $this->fail('Invalid form values must also fail document validation.');
            } catch (ValidationException $exception) {
                $this->assertEqualsCanonicalizing(
                    array_map(fn ($path) => 'blocks.0.data.properties.'.$path, array_keys($validator->errors()->toArray())),
                    array_keys($exception->errors()),
                );
            }
        }
    }

    public function test_relative_element_rules_keep_field_dependencies_local_to_each_block(): void
    {
        $element = new class extends CanvasSchemaBadge
        {
            public function form(Schema $schema): Schema
            {
                return $schema->components([
                    Select::make('mode')->required()->options(['manual' => 'Manual', 'auto' => 'Auto']),
                    TextInput::make('value')->rules(['string']),
                    TextInput::make('confirmation')->requiredWith('value')->same('value'),
                    TextInput::make('note')->requiredIf('mode', 'manual')->rules(['string']),
                    Repeater::make('items')->schema([
                        TextInput::make('code')->rules(['string']),
                        TextInput::make('label')->requiredWith('code')->rules(['string']),
                    ]),
                ]);
            }
        };
        $registry = ElementRegistry::from([$element]);
        $first = ['mode' => 'manual', 'value' => 'First', 'confirmation' => 'First', 'note' => 'Required', 'items' => [['code' => 'P1', 'label' => 'Product']]];
        $second = ['mode' => 'auto'];
        $blocks = array_map(fn ($properties) => [
            'type' => $element->type(), 'data' => $this->elementApiColore() + ['properties' => $properties],
        ], [$first, $second]);
        ElementValidation::validate($element, $first);
        ElementValidation::validate($element, $second);
        app(BlockValidator::class)->validate($blocks, new CanvasDocument([], 300, 200), $registry);

        foreach ([
            [['value' => 'Second'], 'confirmation'],
            [['value' => 'Second', 'confirmation' => 'First'], 'confirmation'],
            [['mode' => 'manual'], 'note'],
            [['items' => [['code' => 'P2']]], 'items.0.label'],
        ] as [$patch, $expected]) {
            $properties = array_replace($second, $patch);
            $validator = Validator::make($properties, ElementValidation::rules($element, $properties));
            $this->assertTrue($validator->fails());
            $this->assertArrayHasKey($expected, $validator->errors()->toArray());
            $blocks[1]['data']['properties'] = $properties;
            try {
                app(BlockValidator::class)->validate($blocks, new CanvasDocument([], 300, 200), $registry);
                $this->fail('Dependent rules must reject invalid values in the second block.');
            } catch (ValidationException $exception) {
                $this->assertSame(['blocks.1.data.properties.'.$expected], array_keys($exception->errors()));
            }
        }
    }

    public function test_partial_image_transforms_are_rejected_with_the_full_document_path(): void
    {
        $properties = ['image_transform' => ['x' => 0, 'y' => 0, 'width' => 1, 'rotation' => 0]];
        $validator = Validator::make($properties, ElementValidation::rules(new ImageElement, $properties));
        $this->assertTrue($validator->fails());
        $this->assertSame(['image_transform.height'], array_keys($validator->errors()->toArray()));
        try {
            app(BlockValidator::class)->validate([
                ['type' => 'asset', 'data' => $this->elementApiColore() + ['properties' => []]],
                ['type' => 'asset', 'data' => $this->elementApiColore() + ['properties' => $properties]],
            ], new CanvasDocument([], 300, 200), ElementRegistry::defaults());
            $this->fail('A partial image transform must not be saved.');
        } catch (ValidationException $exception) {
            $this->assertSame(['blocks.1.data.properties.image_transform.height'], array_keys($exception->errors()));
        }
    }

    public function test_invalid_repeater_shapes_report_validation_errors_instead_of_schema_runtime_errors(): void
    {
        foreach (['invalid', [null], [['label' => 'Item'], false], [['label' => 'Item', 'kind' => ['text']]], array_fill(0, 4, ['label' => 'Item'])] as $items) {
            try {
                ElementValidation::validate(new SchemaOnlyCanvasElement, ['title' => 'Válido', 'items' => $items]);
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

    public array $properties = [];
}
class CanvasSchemaBadge extends CanvasElement
{
    public function type(): string
    {
        return 'badge';
    }

    public function getLabel(): string
    {
        return 'Selo';
    }

    public function getIcon(): string
    {
        return 'heroicon-o-tag';
    }

    public function view(): string
    {
        return 'filament-canvas::elements.text';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('text')->rules(['string'])->default('Novo selo'), Toggle::make('enabled')->default(true), TextInput::make('offset')->numeric()->default(4)]);
    }
}
class CanvasCustomMediaElement extends CanvasElement implements HasElementMedia
{
    public function type(): string
    {
        return 'custom-media';
    }

    public function getLabel(): string
    {
        return 'Media própria';
    }

    public function view(): string
    {
        return 'filament-canvas::elements.image';
    }

    public function mediaCollections(): array
    {
        return ['image' => ['reference_field' => 'image_id', 'max_files' => 1, 'max_size' => 200]];
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
class SchemaOnlyCanvasElement extends CanvasElement
{
    public function type(): string
    {
        return 'schema-only';
    }

    public function getLabel(): string
    {
        return 'Exemplo';
    }

    public function view(): string
    {
        return 'filament-canvas::elements.text';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->rules(['string'])->required()->maxLength(8), Toggle::make('enabled')->default(false)->live(), TextInput::make('note')->rules(['string'])->required()->maxLength(8)->default('Inicial')->visible(fn ($get) => $get('enabled')), TextInput::make('offset')->numeric()->default(4), Repeater::make('items')->maxItems(3)->schema([TextInput::make('label')->rules(['string'])->required()->maxLength(8), Select::make('kind')->options(['text' => 'Text', 'image' => 'Image'])]), Section::make('Advanced')->visible(fn ($get) => $get('enabled'))->schema([TextInput::make('advanced')->required()->rules(['string'])->maxLength(8)])]);
    }
}
