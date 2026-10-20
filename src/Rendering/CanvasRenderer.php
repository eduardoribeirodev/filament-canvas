<?php

namespace EduardoRibeiroDev\FilamentCanvas\Rendering;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasRenderData;
use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Contracts\DocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Contracts\PreviewRenderer;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Persistence\EloquentDocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use EduardoRibeiroDev\FilamentCanvas\Support\MediaLibrary;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class CanvasRenderer implements PreviewRenderer
{
    public function __construct(
        private ElementRegistry $elements,
        private string $template = 'filament-canvas::templates.blank',
        private string|DocumentStore|null $store = null,
    ) {}

    public function render(CanvasDocument $document, EditorContext $context): string
    {
        return $this->renderDocument($document, $context, true);
    }

    public function renderOwner(
        Model&CanvasOwner $owner,
        CanvasDocument $document,
        ?Authenticatable $user = null,
        ?string $panelId = null,
        bool $preview = false,
    ): string {
        Gate::forUser($user)->authorize('update', $owner);

        $context = new EditorContext(
            new DocumentReference($owner::class, $owner->getKey()),
            $user,
            $panelId,
            $owner,
        );

        return ElementRegistry::using(
            $owner::class,
            $this->elements,
            fn () => $this->renderDocument($document, $context, $preview),
        );
    }

    public function renderDocument(CanvasDocument $document, EditorContext $context, bool $preview = false): string
    {
        $store = is_string($this->store) ? app($this->store) : $this->store;
        $operation = function () use ($document, $context, $store, $preview): string {
            $data = [];
            $owner = $context->document;
            if ($store instanceof EloquentDocumentStore) {
                $store->authorize($context, 'preview');
                $owner = $store->owner($context);
                $context = $context->forDocument($owner);
            }
            if ($owner instanceof CanvasRenderData) {
                (new MediaLibrary($context))->validateReferences($document->blocks, $this->elements);
                $data = $owner->canvasRenderData($document, $context);
            }

            return self::renderTemplate($this->template, $data, $preview, [
                'document' => $document, 'context' => $context,
                'blocks' => $this->renderBlocks($document, $context),
            ]);
        };

        return $store instanceof EloquentDocumentStore ? $store->usingElements($operation) : $operation();
    }

    public function renderOutput(array $data, array $variables = []): string
    {
        $operation = fn () => self::renderTemplate($this->template, $data, false, $variables);
        $store = is_string($this->store) ? app($this->store) : $this->store;

        return $store instanceof EloquentDocumentStore ? $store->usingElements($operation) : $operation();
    }

    /** Exporters supply their frozen data to exactly the same template as the preview. */
    public static function renderTemplate(string $template, array $data, bool $preview = false, array $variables = []): string
    {
        return view('filament-canvas::document', array_merge($variables, compact('template', 'data', 'preview')))->render();
    }

    private function renderBlocks(CanvasDocument $document, EditorContext $context): string
    {
        $blocks = '';
        $templates = $this->elements->templates();
        foreach ($document->blocks as $index => $block) {
            $data = $block['data'];
            if (($data['visible'] ?? true) === false) {
                continue;
            }
            $element = $this->elements->get($block['type']);
            $data['properties'] = ElementData::normalize($element, ElementData::from($data));
            $block['data'] = $data;
            $config = $data['properties'];
            $style = $config;
            $align = in_array($style['align'] ?? '', ['left', 'center', 'right', 'justify'], true) ? $style['align'] : 'left';
            $color = Html::color($style['color'] ?? '');
            $font = max(1, min(100, (float) ($style['font_size'] ?? 12)));
            $rotation = -(float) ($data['rotation_deg'] ?? 0);
            $opacity = max(0, min(1, (float) ($config['opacity'] ?? 1)));
            $blend = in_array($config['blend_mode'] ?? '', ['normal', 'multiply', 'screen'], true) ? $config['blend_mode'] : 'normal';
            $geometry = '';
            foreach (['left' => 'x_pt', 'top' => 'y_pt', 'width' => 'width_pt', 'height' => 'height_pt'] as $property => $key) {
                $geometry .= $property.':'.(float) ($data[$key] ?? 0).'pt;';
            }
            $viewData = app(ElementViewData::class)->prepare($element, $block, $context);
            $content = ElementMarkup::wrap($templates[$block['type']], $viewData);
            $blocks .= '<div class="block" data-kind="'.Html::escape($block['type']).'" data-editor-index="'.(int) $index.'" style="'.$geometry.'transform:rotate('.$rotation.'deg);font-size:'.$font.'pt;color:'.$color.';text-align:'.$align.';opacity:'.$opacity.';mix-blend-mode:'.$blend.'">'.$content.'</div>';
        }

        return $blocks;
    }
}
