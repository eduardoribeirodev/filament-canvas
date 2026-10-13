<?php

namespace EduardoRibeiroDev\FilamentCanvas\Livewire;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Contracts\UploadableAssetLibrary;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Rendering\Html;
use EduardoRibeiroDev\FilamentCanvas\Support\BlockValidator;
use EduardoRibeiroDev\FilamentCanvas\Support\EditorConfiguration;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
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
    // The document is persisted only by salvar(), with its authorization and revision.
    public array $inspectorData = [];

    public array $elementCreationData = [];

    public array $inlineTextData = ['rich_text' => null];

    public array $mediaPickerData = ['image' => null, 'reference' => null];

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

    private ?array $inspectorAssetCache = null;

    public function mount(string $documentType, string|int $documentId, ?string $panelId = null, ?string $configuration = null): void
    {
        $this->configuration = $configuration;
        $this->documentType = $documentType;
        $this->documentId = (string) $documentId;
        $this->panelId = $panelId ?? Filament::getCurrentPanel()?->getId() ?? Filament::getDefaultPanel()->getId();
        $this->canvasDataKey = Str::random(40);
        $store = $this->plugin()->resolveDocumentStore($this->documentType);
        $store->authorize($this->context(), 'view');
        $document = $store->load($this->context());
        $this->revision = $document->revision;
        $this->cacheDocument($document);
    }

    public function propertiesForm(Schema $schema): Schema
    {
        if ($this->inspectorType === null) {
            return $schema->statePath('inspectorData')->components([]);
        }

        $schema = $this->plugin()->elements($this->documentType)->get($this->inspectorType)
            ->properties($schema->statePath('inspectorData'));
        $this->configurePropertyFields($schema);

        return $schema;
    }

    /** Native field defaults are evaluated when the element is created, before selection. */
    #[Renderless]
    public function createElement(string $type): array
    {
        $this->plugin()->resolveDocumentStore($this->documentType)->authorize($this->context(), 'update');
        $element = $this->plugin()->elements($this->documentType)->get($type);
        $this->elementCreationData = [];
        $schema = $element->properties(Schema::make($this)->statePath('elementCreationData'));
        $schema->fill();
        $state = ['elementCreationData' => $schema->getRawState()];
        $schema->dehydrateState($state);
        $schema->mutateDehydratedState($state);
        $dados = $element->normalize($state['elementCreationData'] ?? []);

        return ['type' => $type, 'data' => ['nome' => $element->label(), 'dados' => $dados]];
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
            $this->plugin()->elements($this->documentType)->get($type);
        }
        $this->inspectorType = $type;
        $this->inspectorKey = $key;
        $this->inspectorGeneration = $generation;
        $this->inspectorRevision = 0;
        $this->inspectorBlock = $type === null ? [] : ElementData::canonicalizeBlock($block);
        if ($type !== null) {
            $this->inspectorBlock['data']['dados'] = $this->plugin()->elements($this->documentType)->get($type)->normalize($this->inspectorBlock['data']['dados']);
        }
        unset($this->cachedSchemas['propertiesForm'], $this->cachedSchemas['imageForm'], $this->cachedSchemas['imageUploadForm'], $this->cachedSchemas['inlineTextForm']);
        $this->propertiesForm->fill($type === null ? [] : ElementData::from($this->inspectorBlock['data'] ?? []));
        $mediaState = ['reference' => null];
        foreach ($this->elementMediaCollections() as $collection) {
            data_set($mediaState, $collection['picker_field'], data_get($this->inspectorBlock['data'] ?? [], $collection['reference_field'], $collection['max_files'] > 1 ? [] : null));
        }
        $this->imageForm->fill($mediaState);
        $this->imageUploadForm->fill([]);
        $this->resetValidation();

        return ['key' => $key, 'generation' => $generation, 'state' => $this->inspectorData, 'fields' => $this->propertyPaths($this->propertiesForm), 'normalized' => $this->inspectorBlock['data']['dados'] ?? []];
    }

    /** Return only schema-dehydrated properties; hidden/imported properties stay in the local document. */
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
            throw ValidationException::withMessages(['inspectorData' => 'O tipo do elemento selecionado foi alterado.']);
        }
        $this->validateElementEnvelope($block, $this->inspectorType);
        $this->inspectorData = $values;
        $patch = $this->propertiesForm->getState();
        $this->inspectorBlock = ElementData::canonicalizeBlock($block);
        $original = ElementData::from($this->inspectorBlock['data'] ?? []);
        $dados = $this->mergeSchemaState($this->propertiesForm, $original, $patch);
        $element = $plugin->elements($this->documentType)->get($this->inspectorType);
        $normalized = $element->normalize($dados);
        // Return changed root properties with their unrepresented nested state preserved.
        $patch = [];
        foreach ($normalized as $property => $value) {
            if (! array_key_exists($property, $original) || $original[$property] !== $value) {
                $patch[$property] = $value;
            }
        }
        $removed = array_keys(array_diff_key($dados, $normalized));
        $this->inspectorBlock['data']['dados'] = $normalized;
        app(BlockValidator::class)->validate([$this->inspectorBlock], $store->load($context), $plugin->elements($this->documentType));
        $this->inspectorRevision = $revision;

        return [
            'key' => $key, 'generation' => $generation, 'revision' => $revision,
            'patch' => $patch, 'removed' => $removed, 'html' => $this->renderElementContent($this->inspectorBlock),
        ];
    }

    /** Custom PHP / Blade types work in the canvas without a JavaScript renderer. */
    #[Renderless]
    public function renderElement(array $block): string
    {
        $plugin = $this->plugin();
        $store = $plugin->resolveDocumentStore($this->documentType);
        $store->authorize($this->context(), 'preview');
        $this->validateElementEnvelope($block);
        $block = ElementData::canonicalizeBlock($block);
        $element = $plugin->elements($this->documentType)->get($block['type'] ?? '');
        $block['data']['dados'] = $element->normalize(ElementData::from($block['data'] ?? []));
        app(BlockValidator::class)->validate([$block], $store->load($this->context()), $plugin->elements($this->documentType));

        return $this->renderElementContent($block);
    }

    private function validateElementEnvelope(array $block, ?string $type = null): void
    {
        Validator::make($block, [
            'type' => ['required', 'string', Rule::in($type === null ? $this->plugin()->elements($this->documentType)->types() : [$type])],
            'data' => ['required', 'array'], 'data.dados' => ['sometimes', 'array'],
        ])->validate();
    }

    private function renderElementContent(array $block): string
    {
        $element = $this->plugin()->elements($this->documentType)->get($block['type']);
        if ($element instanceof HasElementMedia) {
            $element = $element->withAssetLibrary($this->plugin()->resolveAssetLibrary($this->documentType));
        }

        return $element->render($block, $this->context());
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

    private function mergeSchemaState(Schema $schema, array $dados, array $patch): array
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Field) {
                $path = $component->getStatePath(isAbsolute: false);
                if ($path !== null && Arr::has($patch, $path)) {
                    data_set($dados, $path, data_get($patch, $path));
                }

                continue;
            }
            foreach ($component->getChildSchemas(withHidden: true) as $child) {
                $dados = $this->mergeSchemaState($child, $dados, $patch);
            }
        }

        return $dados;
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
        $definition = $this->inspectorType === null ? new TextElement : $this->plugin()->elements($this->documentType)->get($this->inspectorType);
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

    public function richTextForm(Schema $schema): Schema
    {
        return $schema->statePath('inspectorData')->components([
            RichEditor::make('rich')->label('Texto')->json()->maxLength(20000)
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike', 'highlight'],
                    ['textColor', 'link', 'bulletList', 'orderedList'],
                    ['undo', 'redo', 'clearFormatting'],
                ])
                ->floatingToolbars([])
                ->textColors(['#E60014' => 'Vermelho', '#111111' => 'Preto', '#666666' => 'Cinza', '#2563EB' => 'Azul'])
                ->customTextColors()
                ->extraInputAttributes([
                    'data-canvas-rich-editor' => 'true',
                    'x-init' => <<<'JS'
                        $watch('state', () => {
                            const editor = getEditor();
                            if (editor && !$el.dataset.canvasHydrating && $el.dataset.canvasElementKey) {
                                $dispatch('canvas-rich-text-change', {
                                    key: $el.dataset.canvasElementKey,
                                    html: editor.getHTML(),
                                    document: editor.getJSON(),
                                    text: editor.getText({blockSeparator: '\n'}),
                                });
                            }
                        });
                        (() => {
                            const ready = () => {
                                if (!$el.isConnected) return;
                                if (getEditor()) $dispatch('canvas-rich-text-ready');
                                else setTimeout(ready, 50);
                            };
                            ready();
                        })()
                        JS,
                ]),
        ]);
    }

    public function textStyleForm(Schema $schema): Schema
    {
        return $schema->statePath('inspectorData')->columns(2)->components([
            TextInput::make('font_size')->label('Fonte (pt)')->numeric()->minValue(1)->maxValue(100)->step(.5)
                ->extraInputAttributes([
                    'x-bind:data-canvas-element-key' => "current?._key ?? ''",
                    'x-on:focus' => 'checkpoint()',
                    'x-on:input.stop' => <<<'JS'
                        $dispatch('canvas-style-changed', {key: $el.dataset.canvasElementKey, property: 'font_size', value: $event.target.value})
                        JS,
                ]),
            ColorPicker::make('color')->label('Cor')->hex()
                ->extraAlpineAttributes([
                    'data-canvas-color-picker' => 'true',
                    'x-bind:data-canvas-element-key' => "current?._key ?? ''",
                    'x-init' => <<<'JS'
                        $watch('state', value => {if (!$el.dataset.canvasHydrating) $dispatch('canvas-style-changed', {key: $el.dataset.canvasElementKey, property: 'color', value})}); $nextTick(() => $dispatch('canvas-inspector-ready'))
                        JS,
                ]),
        ]);
    }

    public function imageForm(Schema $schema): Schema
    {
        $fields = [];
        foreach ($this->elementMediaCollections() as $index => $collection) {
            $name = $collection['name'];
            $field = Select::make($collection['picker_field'])->label($collection['label'].' da biblioteca')->placeholder('Buscar uma mídia…')
                ->native(false)->searchable()->allowHtml()->optionsLimit(100)->searchDebounce(250)
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
                $field->multiple()->maxItems($collection['max_files'])
                    ->getOptionLabelsUsing(fn (array $values) => array_intersect_key($this->assetOptions('', $name), array_flip($values)));
            }
            $fields[] = $field;
        }

        return $schema->statePath('mediaPickerData')->components($fields);
    }

    public function referenceForm(Schema $schema): Schema
    {
        return $schema->statePath('mediaPickerData')->components([
            Select::make('reference')->label(fn () => $this->document()->metadata['reference_label'] ?? 'Adicionar item')->hiddenLabel()
                ->placeholder(fn () => $this->document()->metadata['reference_label'] ?? 'Adicionar item')->searchPrompt('Buscar código ou nome…')
                ->native(false)->searchable()->allowHtml()->searchDebounce(250)->optionsLimit(100)
                ->options(fn () => $this->referenceOptions())
                ->getSearchResultsUsing(fn (string $search) => $this->referenceOptions($search))
                ->extraAlpineAttributes([
                    'data-canvas-reference-select' => 'true',
                    'x-bind:data-canvas-element-key' => "current?._key ?? ''",
                    'x-init' => <<<'JS'
                        $watch('state', id => {if (!$el.dataset.canvasHydrating) $dispatch('canvas-reference-selected', {key: $el.dataset.canvasElementKey, id})}); $nextTick(() => $dispatch('canvas-inspector-ready'))
                        JS,
                ]),
        ]);
    }

    public function imageUploadForm(Schema $schema): Schema
    {
        $fields = [];
        foreach ($this->elementMediaCollections() as $collection) {
            $field = FileUpload::make($collection['upload_field'])->label('Enviar '.$collection['label'])->storeFiles(false)
                ->acceptedFileTypes($collection['accepted_mime_types'])->maxSize($collection['max_size'])
                ->maxFiles($collection['max_files'])->imagePreviewHeight('130')
                ->helperText('Até '.number_format($collection['max_size'] / 1024, 1, ',', '.').' MB por arquivo.');
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
        $element = $this->inspectorType === null ? null : $this->plugin()->elements($this->documentType)->get($this->inspectorType);
        $collections = $element instanceof HasElementMedia ? $element->mediaCollections() : ($element === null ? ['image' => [
            'label' => 'Imagem', 'accepted_mime_types' => ['image/png', 'image/jpeg', 'image/webp'],
            'max_files' => 1, 'max_size' => 10240, 'reference_field' => 'imagem_origem',
        ]] : []);
        $result = [];
        foreach ($collections as $name => $collection) {
            $first = $result === [];
            $result[] = [
                ...$collection, 'name' => $name, 'label' => $collection['label'] ?? $name,
                'accepted_mime_types' => $collection['accepted_mime_types'] ?? ['image/png', 'image/jpeg', 'image/webp'],
                'max_files' => max(1, (int) ($collection['max_files'] ?? 1)),
                'max_size' => max(1, (int) ($collection['max_size'] ?? 10240)),
                'reference_field' => $collection['reference_field'] ?? 'imagem_origem',
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
        foreach ($this->inspectorAssets() as $asset) {
            if ($search !== '' && ! str_contains(mb_strtolower((string) ($asset['name'] ?? '')), mb_strtolower($search))) {
                continue;
            }
            $mime = $asset['mime_type'] ?? null;
            if ($mime !== null && $policy !== null && ! collect($policy['accepted_mime_types'])->contains(fn ($accepted) => $accepted === $mime || (str_ends_with($accepted, '/*') && str_starts_with($mime, Str::before($accepted, '*'))))) {
                continue;
            }
            $options[$asset['id']] = $this->optionLabel($asset['name'] ?? 'Mídia', $asset['image'] ?? $asset['url'] ?? null);
        }

        return $options;
    }

    /** Context supplied by the document owner, also available to custom property views. */
    public function getEditorMetadata(): array
    {
        return $this->document()->metadata;
    }

    public function referenceOptions(string $search = ''): array
    {
        $options = [];
        foreach ($this->document()->metadata['reference_options'] ?? [] as $reference) {
            if ($search !== '' && ! str_contains(mb_strtolower((string) ($reference['label'] ?? '')), mb_strtolower($search))) {
                continue;
            }
            $options[$reference['id']] = $this->optionLabel($reference['label'] ?? '', $reference['image'] ?? null);
        }

        return $options;
    }

    private function optionLabel(string $label, ?string $image): string
    {
        $url = Html::imageUrl($image);
        $preview = $url === '' ? '' : '<img src="'.$url.'" alt="" style="width:40px;height:40px;object-fit:contain;flex:none;border-radius:4px">';

        return '<span style="display:flex;align-items:center;gap:8px;min-height:40px">'.$preview.'<span style="white-space:normal">'.e($label).'</span></span>';
    }

    private function inspectorAssets(): array
    {
        if ($this->inspectorAssetCache !== null) {
            return $this->inspectorAssetCache;
        }
        $this->plugin()->resolveDocumentStore($this->documentType)->authorize($this->context(), 'view');

        return $this->inspectorAssetCache = $this->plugin()->resolveAssetLibrary($this->documentType)->assets($this->context());
    }

    public function uploadAsset(?string $collection = null): array
    {
        $context = $this->context();
        $this->plugin()->resolveDocumentStore($this->documentType)->authorize($context, 'update');
        $library = $this->plugin()->resolveAssetLibrary($this->documentType);
        if (! $library instanceof UploadableAssetLibrary) {
            throw ValidationException::withMessages(['imageUploadData.file' => 'Esta biblioteca não permite enviar mídias.']);
        }
        $policy = collect($this->elementMediaCollections())->first(fn ($item) => $collection === null || $item['name'] === $collection);
        if ($policy === null) {
            throw ValidationException::withMessages(['imageUploadData.file' => 'O elemento não possui esta coleção de mídia.']);
        }
        $state = $this->imageUploadForm->getState();
        $files = Arr::wrap(data_get($state, $policy['upload_field']));
        if ($files === [] || collect($files)->contains(fn ($file) => ! $file instanceof TemporaryUploadedFile)) {
            throw ValidationException::withMessages(['imageUploadData.'.$policy['upload_field'] => 'Selecione um arquivo para enviar.']);
        }
        try {
            $inserted = [];
            foreach ($files as $file) {
                $inserted[] = $library->upload($context, $file);
            }
            $this->inspectorAssetCache = null;
            $assets = $library->assets($context);
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
        $this->inspectorAssetCache = null;

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

        return new EditorContext(new DocumentReference($this->documentType, $this->documentId), auth($panel->getAuthGuard())->user(), $this->panelId);
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
            $document = $this->plugin()->resolveDocumentStore($this->documentType)->load($this->context());
            $this->cacheDocument($document);
        }

        return $document;
    }

    private function normalizeBlocks(array $blocks): array
    {
        $registry = $this->plugin()->elements($this->documentType);
        $blocks = ElementData::canonicalizeBlocks($blocks);
        foreach ($blocks as &$block) {
            if (is_array($block) && is_array($block['data']['dados'] ?? null) && in_array($block['type'] ?? null, $registry->types(), true)) {
                $block['data']['dados'] = $registry->get($block['type'])->normalize($block['data']['dados']);
            }
        }
        unset($block);

        return $blocks;
    }

    public function salvar(array $blocks): array
    {
        $plugin = $this->plugin();
        $context = $this->context();
        $store = $plugin->resolveDocumentStore($this->documentType);
        $store->authorize($context, 'update');
        $document = $store->load($context);
        $blocks = $this->normalizeBlocks($blocks);
        app(BlockValidator::class)->validate($blocks, $document, $plugin->elements($this->documentType));
        $result = $store->save($context, $blocks, $this->revision);
        $this->revision = $result->revision;
        $this->cacheDocument(new CanvasDocument($result->blocks, $document->width, $document->height, $result->revision, $document->metadata));
        Notification::make()->title('Composição salva')->success()->send();

        return $result->blocks;
    }

    public function save(array $blocks): array
    {
        return $this->salvar($blocks);
    }

    public function preview(array $blocks): string
    {
        $plugin = $this->plugin();
        $context = $this->context();
        $store = $plugin->resolveDocumentStore($this->documentType);
        $store->authorize($context, 'preview');
        $document = $store->load($context);
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

        return view($plugin->getEditorView($this->documentType), array_merge($document->metadata, [
            'metadata' => $document->metadata,
            'initial' => $document->blocks,
            'assets' => $assets,
            'canUploadAssets' => $plugin->resolveAssetLibrary($this->documentType) instanceof UploadableAssetLibrary,
            'width' => $document->width,
            'height' => $document->height,
            'larguraPt' => $document->width,
            'alturaPt' => $document->height,
            'previewHtml' => $plugin->resolvePreviewRenderer($this->documentType)->render($document, $context),
            'elementLabels' => $plugin->elements($this->documentType)->labels(),
            'elementTypes' => $plugin->elements($this->documentType)->metadataForEditor(),
            'elementDefinitions' => $plugin->elements($this->documentType)->metadataForEditor(),
        ]));
    }
}
