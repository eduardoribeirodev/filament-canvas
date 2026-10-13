<?php

namespace EduardoRibeiroDev\FilamentCanvas\Rendering;

use EduardoRibeiroDev\FilamentCanvas\Contracts\AssetLibrary;
use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasRenderData;
use EduardoRibeiroDev\FilamentCanvas\Contracts\DocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Contracts\PreviewRenderer;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Persistence\EloquentDocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use EduardoRibeiroDev\FilamentCanvas\Support\EmptyAssetLibrary;

class CanvasRenderer implements PreviewRenderer
{
    public function __construct(
        private ElementRegistry $elements,
        private AssetLibrary $assets = new EmptyAssetLibrary,
        private string $template = 'filament-canvas::templates.blank',
        private string|DocumentStore|null $store = null,
    ) {}

    public function render(CanvasDocument $document, EditorContext $context): string
    {
        return $this->renderDocument($document, $context, true);
    }

    public function renderDocument(CanvasDocument $document, EditorContext $context, bool $preview = false): string
    {
        $store = is_string($this->store) ? app($this->store) : $this->store;
        $operation = function () use ($document, $context, $store, $preview): string {
            $data = [];
            if ($store instanceof EloquentDocumentStore) {
                $store->authorize($context, 'preview');
                $owner = $store->owner($context);
                if ($owner instanceof CanvasRenderData) {
                    $data = $owner->canvasRenderData($document, $context);
                }
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
        // This lazy snapshot belongs to one render/context, never a shared user cache.
        $assets = new class($this->assets) implements AssetLibrary
        {
            private ?array $snapshot = null;

            public function __construct(private AssetLibrary $library) {}

            public function assets(EditorContext $context): array
            {
                return $this->snapshot ??= $this->library->assets($context);
            }
        };
        $blocks = '';
        foreach ($document->blocks as $index => $block) {
            $data = $block['data'];
            if (($data['visivel'] ?? true) === false) {
                continue;
            }
            $element = $this->elements->get($block['type']);
            $data['dados'] = $element->normalize(ElementData::from($data));
            $block['data'] = $data;
            $config = $data['dados'];
            $style = $config;
            $align = in_array($style['align'] ?? '', ['left', 'center', 'right', 'justify'], true) ? $style['align'] : 'left';
            $color = Html::color($style['color'] ?? '');
            $font = max(1, min(100, (float) ($style['font_size'] ?? 12)));
            $rotation = -(float) ($data['rotacao_graus'] ?? 0);
            $opacity = max(0, min(1, (float) ($config['opacity'] ?? 1)));
            $blend = in_array($config['blend_mode'] ?? '', ['normal', 'multiply', 'screen'], true) ? $config['blend_mode'] : 'normal';
            $geometry = '';
            foreach (['left' => 'x_pt', 'top' => 'y_pt', 'width' => 'largura_pt', 'height' => 'altura_pt'] as $property => $key) {
                $geometry .= $property.':'.(float) ($data[$key] ?? 0).'pt;';
            }
            if ($element instanceof HasElementMedia) {
                $element = $element->withAssetLibrary($assets);
            }
            $content = $element->render($block, $context);
            if ($element->capabilities()['image'] ?? false) {
                $transform = $config['image_transform'] ?? null;
                $flipX = ($config['flip_x'] ?? false) ? -1 : 1;
                $flipY = ($config['flip_y'] ?? false) ? -1 : 1;
                if (is_array($transform)) {
                    $imageStyle = 'position:absolute;left:'.(float) ($transform['x'] ?? 0).'pt;top:'.(float) ($transform['y'] ?? 0).'pt;width:'.(float) ($transform['width'] ?? 1).'pt;height:'.(float) ($transform['height'] ?? 1).'pt;max-width:none;object-fit:fill;transform:rotate('.(-(float) ($transform['rotation'] ?? 0)).'deg) scale('.$flipX.','.$flipY.')';
                } else {
                    $fit = ($config['fit'] ?? '') === 'cover' ? 'cover' : 'contain';
                    $imageStyle = 'object-fit:'.$fit.';object-position:'.((float) ($config['crop_x'] ?? .5) * 100).'% '.((float) ($config['crop_y'] ?? .5) * 100).'%;transform:scale('.$flipX.','.$flipY.')';
                }
                $content = preg_replace('/class="image"(?!\s+style=)/', 'class="image" style="'.Html::escape($imageStyle).'"', $content);
            }
            $link = Html::linkUrl($config['link_url'] ?? null);
            if ($link !== '') {
                $content .= '<a class="block-link" href="'.$link.'" aria-label="Abrir link"></a>';
            }
            $blocks .= '<div class="block" data-kind="'.Html::escape($block['type']).'" data-editor-index="'.(int) $index.'" style="'.$geometry.'transform:rotate('.$rotation.'deg);font-size:'.$font.'pt;color:'.$color.';text-align:'.$align.';opacity:'.$opacity.';mix-blend-mode:'.$blend.'">'.$content.'</div>';
        }

        return $blocks;
    }
}
