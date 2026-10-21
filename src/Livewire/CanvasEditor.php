<?php

namespace EduardoRibeiroDev\FilamentCanvas\Livewire;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Persistence\EloquentDocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Rendering\Html;
use EduardoRibeiroDev\FilamentCanvas\Support\BlockValidator;
use EduardoRibeiroDev\FilamentCanvas\Support\CanvasElements;
use EduardoRibeiroDev\FilamentCanvas\Support\EditorConfiguration;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use EduardoRibeiroDev\FilamentCanvas\Support\MediaLibrary;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\HasMedia;

class CanvasEditor extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public string $documentType;

    #[Locked]
    public string $documentId;

    #[Locked]
    public string $panelId;

    #[Locked]
    public string $revision;

    #[Locked]
    public string $canvasDataKey;

    // These deferred form values bridge native Filament controls to local Alpine state.
    // The document is persisted only by save(), with its authorization and revision.
    public array $inspectorData = [];

    public array $elementCreationData = [];

    public array $inlineTextData = ['rich_text' => null];

    public array $mediaPickerData = ['image' => null];

    #[Locked]
    public ?string $inspectorType = null;

    #[Locked]
    public ?string $inspectorKey = null;

    #[Locked]
    public int $inspectorGeneration = 0;

    #[Locked]
    public array $inspectorBlock = [];

    #[Locked]
    public int $inspectorRevision = 0;

    public array $imageUploadData = ['file' => null];

    #[Locked]
    public ?string $configuration = null;

    private ?CanvasPlugin $configuredPlugin = null;

    private array $inspectorAssetCache = [];

    public function mount(string $documentType, string|int $documentId, ?string $panelId = null, ?string $configuration = null): void
    {
        $this->configuration = $configuration;
        $this->documentType = $documentType;
        $this->documentId = (string) $documentId;
        $this->panelId = $panelId ?? Filament::getCurrentPanel()?->getId() ?? Filament::getDefaultPanel()->getId();
        $this->canvasDataKey = Str::random(40);
        $store = $this->plugin()->resolveDocumentStore($this->documentType);
        $store->authorize($this->context(), 'view');
        $document = $this->loadCanvasDocument($this->context());
        $this->revision = $document->revision;
        $this->cacheDocument($document);
    }

    public function propertiesForm(Schema $schema): Schema
    {
        $schema->key('propertiesForm')->statePath('inspectorData');
        if ($this->inspectorType === null) {
            return $schema->components([]);
        }

        $schema = CanvasElements::resolve($this->plugin()->elements($this->documentType), $this->inspectorType)
            ->form($schema);
        $this->configurePropertyFields($schema);

        return $schema;
    }

    /** Native field defaults are evaluated when the element is created, before selection. */
    #[Renderless]
    public function createElement(string $type): array
    {
        $this->plugin()->resolveDocumentStore($this->documentType)->authorize($this->context(), 'update');
        $element = CanvasElements::resolve($this->plugin()->elements($this->documentType), $type);
        $this->elementCreationData = [];
        $schema = $element->form(Schema::make($this)->statePath('elementCreationData'));
        $schema->fill();
        $state = ['elementCreationData' => $schema->getRawState()];
        $schema->dehydrateState($state);
        $schema->mutateDehydratedState($state);
        $properties = ElementData::normalize($element, $state['elementCreationData'] ?? []);

        return ['type' => $type, 'data' => ['name' => (string) ($element->getLabel() ?? $type), 'properties' => $properties]];
    }

    /** Selection tokens prevent requests for an old form from replacing a newer selection. */
    public function selectInspector(?string $type, ?string $key, array $block, int $generation): array
    {
        $this->plugin()->resolveDocumentStore($this->documentType)->authorize($this->context(), 'view');
        if ($generation < $this->inspectorGeneration) {
            return ['stale' => true];
        }
        if ($type !== null) {
            $this->validateElementEnvelope($block, $type);
            CanvasElements::resolve($this->plugin()->elements($this->documentType), $type);
        }
        $this->inspectorType = $type;
        $this->inspectorAssetCache = [];
        $this->inspectorKey = $key;
        $this->inspectorGeneration = $generation;
        $this->inspectorRevision = 0;
        $this->inspectorBlock = $type === null ? [] : $block;
        if ($type !== null) {
            $this->inspectorBlock['data']['properties'] = ElementData::normalize(CanvasElements::resolve($this->plugin()->elements($this->documentType), $type), $this->inspectorBlock['data']['properties']);
        }
        unset($this->cachedSchemas['propertiesForm'], $this->cachedSchemas['imageForm'], $this->cachedSchemas['imageUploadForm'], $this->cachedSchemas['inlineTextForm']);
        $properties = $type === null ? [] : ElementData::from($this->inspectorBlock['data'] ?? []);
        $this->inspectorData = $properties;
        foreach ($this->propertiesForm->getFlatFields(withHidden: true) as $field) {
            $path = Str::after($field->getStatePath(), 'inspectorData.');
            // Boolean casts turn missing state into false; retain the field's declared default.
            if ($field instanceof Toggle && ! Arr::has($properties, $path) && ($default = $field->getDefaultState()) !== null) {
                data_set($properties, $path, $default);
            }
        }
        $this->propertiesForm->fill($properties);
        $mediaState = ['reference' => null];
        foreach ($this->elementMediaCollections() as $collection) {
            data_set($mediaState, $collection['picker_field'], data_get($this->inspectorBlock['data'] ?? [], $collection['reference_field'], $collection['max_files'] > 1 ? [] : null));
        }
        $this->imageForm->fill($mediaState);
        $this->imageUploadForm->fill([]);
        $this->resetValidation();

        return ['key' => $key, 'generation' => $generation, 'state' => $this->inspectorData, 'fields' => $this->propertyPaths($this->propertiesForm), 'normalized' => $this->inspectorBlock['data']['properties'] ?? []];
    }

    /** Return schema-dehydrated properties while retaining values outside the active fields. */
    public function updateInspector(array $values, string $key, int $generation, int $revision, array $block): array
    {
        $plugin = $this->plugin();
        $context = $this->context();
        $store = $plugin->resolveDocumentStore($this->documentType);
        $store->authorize($context, 'update');
        if ($key !== $this->inspectorKey || $generation !== $this->inspectorGeneration || $revision < $this->inspectorRevision) {
            return ['stale' => true, 'key' => $key, 'generation' => $generation];
        }
        if (($block['type'] ?? null) !== $this->inspectorType) {
            throw ValidationException::withMessages(['inspectorData' => __('filament-canvas::canvas.element_type_changed')]);
        }
        $this->validateElementEnvelope($block, $this->inspectorType);
        $this->inspectorData = $values;
        $patch = $this->propertiesForm->getState();
        $this->inspectorBlock = $block;
        $original = ElementData::from($this->inspectorBlock['data'] ?? []);
        $properties = $this->mergeSchemaState($this->propertiesForm, $original, $patch);
        $element = CanvasElements::resolve($plugin->elements($this->documentType), $this->inspectorType);
        $normalized = ElementData::normalize($element, $properties);
        // Return changed root properties with their unrepresented nested state preserved.
        $patch = [];
        foreach ($normalized as $property => $value) {
            if (! array_key_exists($property, $original) || $original[$property] !== $value) {
                $patch[$property] = $value;
            }
        }
        $removed = array_keys(array_diff_key($properties, $normalized));
        $this->inspectorBlock['data']['properties'] = $normalized;
        app(BlockValidator::class)->validate([$this->inspectorBlock], $store->load($context), $plugin->elements($this->documentType));
        $this->inspectorRevision = $revision;

        return [
            'key' => $key, 'generation' => $generation, 'revision' => $revision,
            'patch' => $patch, 'removed' => $removed,
        ];
    }

    private function validateElementEnvelope(array $block, ?string $type = null): void
    {
        Validator::make($block, [
            'type' => ['required', 'string', Rule::in($type === null ? CanvasElements::types($this->plugin()->elements($this->documentType)) : [$type])],
            'data' => ['required', 'array'], 'data.properties' => ['sometimes', 'array'],
        ])->validate();
    }

    private function propertyPaths(Schema $schema): array
    {
        $paths = [];
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Field) {
                $absolutePath = $component->getStatePath();
                if ($absolutePath !== null) {
                    $paths[] = ['path' => Str::after($absolutePath, 'inspectorData.'), 'list' => $component instanceof Repeater, 'simple' => $component instanceof Repeater ? $component->getSimpleField()?->getStatePath(isAbsolute: false) : null, 'numeric' => $component instanceof TextInput && $component->isNumeric(), 'integer' => $component instanceof TextInput && $component->isInteger(), 'rich' => $component instanceof RichEditor];
                }

                continue;
            }
            foreach ($component->getChildSchemas(withHidden: true) as $child) {
                $paths = [...$paths, ...$this->propertyPaths($child)];
            }
        }

        return $paths;
    }

    private function mergeSchemaState(Schema $schema, array $properties, array $patch): array
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Field) {
                $path = $component->getStatePath(isAbsolute: false);
                if ($path !== null && Arr::has($patch, $path)) {
                    data_set($properties, $path, data_get($patch, $path));
                }

                continue;
            }
            foreach ($component->getChildSchemas(withHidden: true) as $child) {
                $properties = $this->mergeSchemaState($child, $properties, $patch);
            }
        }

        return $properties;
    }

    private function configurePropertyFields(Schema $schema): void
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Field) {
                $component->live(condition: false)->stateBindingModifiers([]);
            }
            if ($component instanceof ColorPicker) {
                $component->extraAlpineAttributes(['data-canvas-color-picker' => 'true', 'x-init' => "\$watch('state', () => { \$el.dataset.canvasColorUpdatedAt = String(Date.now()); }); \$nextTick(() => \$dispatch('canvas-inspector-ready'))"], merge: true);
            }
            if ($component instanceof RichEditor) {
                $component->extraInputAttributes([
                    'data-canvas-rich-editor' => 'true',
                    'x-init' => <<<'JS'
                        $watch('state', () => {
                            const editor = getEditor();
                            if (editor && !$el.dataset.canvasHydrating && $el.dataset.canvasElementKey) {
                                $dispatch('canvas-rich-text-change', {key: $el.dataset.canvasElementKey, html: editor.getHTML(), document: editor.getJSON(), text: editor.getText({blockSeparator: '\n'})});
                            }
                        });
                        (() => { const ready = () => { if (!$el.isConnected) return; if (getEditor()) $dispatch('canvas-rich-text-ready'); else setTimeout(ready, 50); }; ready(); })()
                        JS,
                ], merge: true);
            }
            foreach ($component->getChildSchemas(withHidden: true) as $child) {
                $this->configurePropertyFields($child);
            }
        }
    }

    /** One native deferred editor on the stage; the canvas commits its JSON as a single edit. */
    public function inlineTextForm(Schema $schema): Schema
    {
        $definition = $this->inspectorType === null ? new TextElement : CanvasElements::resolve($this->plugin()->elements($this->documentType), $this->inspectorType);
        $textElement = $definition instanceof TextElement ? $definition : new TextElement;

        return $schema->statePath('inlineTextData')->components([
            $textElement->inlineEditorField()->key('inline-'.($this->inspectorType ?? 'text'))->live(condition: false)->stateBindingModifiers([])
                ->extraInputAttributes([
                    'data-canvas-inline-rich-editor' => 'true',
                    'x-init' => <<<'JS'
                        (() => {
                            const ready = () => {
                                if (!$el.isConnected) return;
                                const editor = getEditor();
                                if (!editor) { setTimeout(ready, 50); return; }
                                const notify = () => {
                                    if (!$el.dataset.canvasHydrating) $dispatch('canvas-inline-text-change', {key: $el.dataset.canvasElementKey, document: editor.getJSON(), html: editor.getHTML(), text: editor.getText({blockSeparator: '\n'})});
                                };
                                editor.on('update', notify);
                                $dispatch('canvas-inline-text-ready');
                            };
                            ready();
                        })()
                        JS,
                ]),
        ]);
    }

    public function imageForm(Schema $schema): Schema
    {
        $fields = [];
        foreach ($this->elementMediaCollections() as $index => $collection) {
            $name = $collection['name'];
            $field = Select::make($collection['picker_field'])
                ->label(__('filament-canvas::canvas.from_library', ['label' => $collection['label']]))
                ->placeholder(__('filament-canvas::canvas.search_media'))
                ->native(false)
                ->searchable()
                ->allowHtml()
                ->optionsLimit(100)
                ->searchDebounce(250)
                ->options(fn () => $this->assetOptions('', $name))
                ->getSearchResultsUsing(fn (string $search) => $this->assetOptions($search, $name))
                ->getOptionLabelUsing(fn ($value) => $this->assetOptions('', $name)[$value] ?? null)
                ->extraAlpineAttributes([
                    'data-canvas-image-select' => 'true',
                    'data-canvas-media-collection' => $name,
                    'x-init' => <<<'JS'
                        $watch('state', id => {if (!$el.dataset.canvasHydrating) $dispatch('canvas-asset-selected', {key: $el.dataset.canvasElementKey, collection: $el.dataset.canvasMediaCollection, id})}); $nextTick(() => $dispatch('canvas-inspector-ready'))
                        JS,
                ]);
            if ($collection['max_files'] > 1) {
                $field->multiple()
                    ->maxItems($collection['max_files'])
                    ->getOptionLabelsUsing(fn (array $values) => array_intersect_key($this->assetOptions('', $name), array_flip($values)));
            }
            $fields[] = $field;
        }

        return $schema->statePath('mediaPickerData')->components($fields);
    }

    public function imageUploadForm(Schema $schema): Schema
    {
        $fields = [];
        foreach ($this->elementMediaCollections() as $collection) {
            $field = FileUpload::make($collection['upload_field'])
                ->label(__('filament-canvas::canvas.upload_label', ['label' => $collection['label']]))
                ->storeFiles(false)
                ->acceptedFileTypes($collection['accepted_mime_types'])
                ->maxSize($collection['max_size'])
                ->maxFiles($collection['max_files'])
                ->imagePreviewHeight('130')
                ->helperText(__('filament-canvas::canvas.max_file_size', ['size' => number_format($collection['max_size'] / 1024, 1)]));
            if ($collection['max_files'] > 1) {
                $field->multiple();
            }
            if (collect($collection['accepted_mime_types'])->every(fn ($mime) => str_starts_with($mime, 'image/'))) {
                $field->image();
            }
            $field->acceptedFileTypes($collection['accepted_mime_types']);
            $fields[] = $field;
        }

        return $schema->statePath('imageUploadData')->components($fields);
    }

    /** Collection metadata drives both native pickers and upload validation. */
    public function elementMediaCollections(): array
    {
        $element = $this->inspectorType === null ? null : CanvasElements::resolve($this->plugin()->elements($this->documentType), $this->inspectorType);
        $collections = $element instanceof HasElementMedia ? CanvasElements::mediaCollectionMetadata($element) : ($element === null ? ['image' => [
            'label' => __('filament-canvas::canvas.image'), 'accepted_mime_types' => ['image/png', 'image/jpeg', 'image/webp'],
            'max_files' => 1, 'max_size' => 10240, 'reference_field' => 'image_id',
        ]] : []);
        $result = [];
        foreach ($collections as $name => $collection) {
            $first = $result === [];
            $result[] = [
                ...$collection, 'name' => $name, 'label' => $collection['label'] ?? $name,
                'accepted_mime_types' => $collection['accepted_mime_types'] ?? ['image/png', 'image/jpeg', 'image/webp'],
                'max_files' => max(1, (int) ($collection['max_files'] ?? 1)),
                'max_size' => max(1, (int) ($collection['max_size'] ?? 10240)),
                'reference_field' => $collection['reference_field'] ?? 'image_id',
                'picker_field' => $first ? 'image' : 'collections.'.$name,
                'upload_field' => $first ? 'file' : 'collections.'.$name.'.file',
            ];
        }

        return $result;
    }

    public function assetOptions(string $search = '', ?string $collection = null): array
    {
        $policy = collect($this->elementMediaCollections())->first(fn ($item) => $collection === null || $item['name'] === $collection);
        $options = [];
        foreach ($this->inspectorAssets($policy['name'] ?? null) as $asset) {
            if ($search !== '' && ! str_contains(mb_strtolower((string) ($asset['name'] ?? '')), mb_strtolower($search))) {
                continue;
            }
            $mime = $asset['mime_type'] ?? null;
            if ($mime !== null && $policy !== null && ! collect($policy['accepted_mime_types'])->contains(fn ($accepted) => $accepted === $mime || (str_ends_with($accepted, '/*') && str_starts_with($mime, Str::before($accepted, '*'))))) {
                continue;
            }
            $options[$asset['id']] = $this->optionLabel($asset['name'] ?? __('filament-canvas::canvas.media'), $asset['image'] ?? $asset['url'] ?? null);
        }

        return $options;
    }

    /** Context supplied by the document owner, also available to custom property views. */
    public function getEditorMetadata(): array
    {
        return $this->document()->metadata;
    }

    private function optionLabel(string $label, ?string $image): string
    {
        $url = Html::imageUrl($image);
        $preview = $url === '' ? '' : '<img src="'.$url.'" alt="" style="width:40px;height:40px;object-fit:contain;flex:none;border-radius:4px">';

        return '<span style="display:flex;align-items:center;gap:8px;min-height:40px">'.$preview.'<span style="white-space:normal">'.e($label).'</span></span>';
    }

    private function inspectorAssets(?string $collection = null): array
    {
        $key = $collection ?? '*';
        if (isset($this->inspectorAssetCache[$key])) {
            return $this->inspectorAssetCache[$key];
        }
        $context = $this->context();
        $this->plugin()->resolveDocumentStore($this->documentType)->authorize($context, 'view');
        $library = new MediaLibrary($context);
        $elements = $this->plugin()->elements($this->documentType);
        if ($collection !== null && $this->inspectorType !== null) {
            $element = CanvasElements::resolve($elements, $this->inspectorType);

            return $this->inspectorAssetCache[$key] = $element instanceof HasElementMedia ? $library->assets($element, $collection) : [];
        }
        $assets = [];
        foreach (CanvasElements::types($elements) as $type) {
            $element = CanvasElements::resolve($elements, $type);
            if ($element instanceof HasElementMedia) {
                foreach ($library->assets($element) as $asset) {
                    $assets[$asset['id']] = $asset;
                }
            }
        }

        return $this->inspectorAssetCache[$key] = array_values($assets);
    }

    public function uploadAsset(?string $collection = null): array
    {
        $context = $this->context();
        $this->plugin()->resolveDocumentStore($this->documentType)->authorize($context, 'update');
        $policy = collect($this->elementMediaCollections())->first(fn ($item) => $collection === null || $item['name'] === $collection);
        if ($policy === null) {
            throw ValidationException::withMessages(['imageUploadData.file' => __('filament-canvas::canvas.missing_collection')]);
        }
        $element = $this->inspectorType !== null
            ? CanvasElements::resolve($this->plugin()->elements($this->documentType), $this->inspectorType)
            : CanvasElements::resolve($this->plugin()->elements($this->documentType), 'asset');
        $library = new MediaLibrary($context);
        $state = $this->imageUploadForm->getState();
        $files = Arr::wrap(data_get($state, $policy['upload_field']));
        if ($files === [] || collect($files)->contains(fn ($file) => ! $file instanceof TemporaryUploadedFile)) {
            throw ValidationException::withMessages(['imageUploadData.'.$policy['upload_field'] => __('filament-canvas::canvas.select_file')]);
        }
        try {
            $inserted = [];
            foreach ($files as $file) {
                $inserted[] = $library->upload($file, $element, $policy['name']);
            }
            $this->inspectorAssetCache = [];
            $assets = $this->inspectorAssets();
            $rawState = $this->imageUploadData;
            data_set($rawState, $policy['upload_field'], null);
            $this->imageUploadForm->fill($rawState);

            return ['asset' => $inserted[0], 'inserted' => $inserted, 'assets' => $assets, 'collection' => $policy['name']];
        } finally {
            foreach ($files as $file) {
                $file->delete();
            }
        }
    }

    public function refreshAssets(): array
    {
        $this->inspectorAssetCache = [];

        return $this->inspectorAssets();
    }

    private function plugin(): CanvasPlugin
    {
        if ($this->configuredPlugin !== null) {
            return $this->configuredPlugin;
        }
        $plugin = Filament::getPanel($this->panelId)->getPlugin('filament-canvas');
        abort_unless($plugin instanceof CanvasPlugin, 404);
        if ($this->configuration !== null) {
            $options = EditorConfiguration::decode($this->configuration, $this->documentType, $this->documentId, $this->panelId);
            $plugin = $plugin->forEditor($this->documentType, $options);
        }

        return $this->configuredPlugin = $plugin;
    }

    private function context(): EditorContext
    {
        $panel = Filament::getPanel($this->panelId);

        $context = new EditorContext(new DocumentReference($this->documentType, $this->documentId), auth($panel->getAuthGuard())->user(), $this->panelId);
        $store = $this->plugin()->resolveDocumentStore($this->documentType);

        return $store instanceof EloquentDocumentStore ? $context->forDocument($store->owner($context)) : $context;
    }

    private function cacheKey(): string
    {
        return 'filament-canvas:'.$this->panelId.':'.($this->context()->user?->getAuthIdentifier() ?? 'guest').':'.$this->canvasDataKey;
    }

    private function cacheDocument(CanvasDocument $document): void
    {
        Cache::put($this->cacheKey(), $document, now()->addHours(6));
    }

    private function document(): CanvasDocument
    {
        $this->plugin()->resolveDocumentStore($this->documentType)->authorize($this->context(), 'view');
        $document = Cache::get($this->cacheKey());
        if (! $document instanceof CanvasDocument) {
            $document = $this->loadCanvasDocument($this->context());
            $this->cacheDocument($document);
        }

        return $document;
    }

    private function loadCanvasDocument(EditorContext $context): CanvasDocument
    {
        $store = $this->plugin()->resolveDocumentStore($this->documentType);
        if ($store instanceof EloquentDocumentStore && method_exists($context->document, 'getCanvas')) {
            return $store->usingElements(fn () => $context->document->getCanvas($context->user));
        }

        return $store->load($context);
    }

    private function normalizeBlocks(array $blocks): array
    {
        $definitions = $this->plugin()->elements($this->documentType);
        foreach ($blocks as &$block) {
            if (is_array($block) && is_array($block['data']['properties'] ?? null) && in_array($block['type'] ?? null, CanvasElements::types($definitions), true)) {
                $block['data']['properties'] = ElementData::normalize(CanvasElements::resolve($definitions, $block['type']), $block['data']['properties']);
            }
        }
        unset($block);

        return $blocks;
    }

    public function save(array $blocks): array
    {
        $plugin = $this->plugin();
        $context = $this->context();
        $store = $plugin->resolveDocumentStore($this->documentType);
        $store->authorize($context, 'update');
        $document = $this->loadCanvasDocument($context);
        if ($store instanceof EloquentDocumentStore && method_exists($context->document, 'saveCanvas')) {
            $result = $store->usingElements(fn () => $context->document->saveCanvas(
                $blocks,
                expectedRevision: $this->revision,
                user: $context->user,
            ));
        } else {
            $blocks = $this->normalizeBlocks($blocks);
            app(BlockValidator::class)->validate($blocks, $document, $plugin->elements($this->documentType));
            $result = $store->save($context, $blocks, $this->revision);
        }
        $this->revision = $result->revision;
        $this->cacheDocument(new CanvasDocument($result->blocks, $document->width, $document->height, $result->revision, $document->metadata));
        Notification::make()->title(__('filament-canvas::canvas.saved'))->success()->send();

        return $result->blocks;
    }

    public function preview(array $blocks): string
    {
        $plugin = $this->plugin();
        $context = $this->context();
        $store = $plugin->resolveDocumentStore($this->documentType);
        $store->authorize($context, 'preview');
        $document = $this->loadCanvasDocument($context);
        $blocks = $this->normalizeBlocks($blocks);
        app(BlockValidator::class)->validate($blocks, $document, $plugin->elements($this->documentType));

        return $plugin->resolvePreviewRenderer($this->documentType)->render($document->withBlocks($blocks), $context);
    }

    public function render(): View
    {
        $plugin = $this->plugin();
        $context = $this->context();
        $store = $plugin->resolveDocumentStore($this->documentType);
        $store->authorize($context, 'view');
        $document = $this->document();
        $assets = $this->inspectorAssets();
        $canUpload = $context->document instanceof HasMedia;
        $elements = $plugin->elements($this->documentType);
        foreach ($this->inspectorType === null ? CanvasElements::types($elements) : [$this->inspectorType] as $type) {
            $element = CanvasElements::resolve($elements, $type);
            if ($element instanceof HasElementMedia && collect($element->mediaCollections())->contains(fn ($collection) => isset($collection['upload_owner']))) {
                $canUpload = true;
            }
        }

        return view($plugin->getEditorView($this->documentType), [
            'metadata' => $document->metadata,
            'initial' => $document->blocks,
            'assets' => $assets,
            'canUploadAssets' => $canUpload,
            'width' => $document->width,
            'height' => $document->height,
            'previewHtml' => $plugin->resolvePreviewRenderer($this->documentType)->render($document, $context),
            'elementLabels' => CanvasElements::labels($elements),
            'elementGroups' => CanvasElements::toolbarGroups($elements),
            'elementIcons' => CanvasElements::icons($elements),
            'elementTemplates' => CanvasElements::templates($elements),
            'elementTypes' => CanvasElements::metadataForEditor($elements),
        ]);
    }
}
