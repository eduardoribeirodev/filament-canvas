<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasRenderData;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasPage;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Rendering\CanvasRenderer;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

class RenderingApiTest extends TestCase
{
    public function test_elements_receive_native_data_and_isolated_page_context_in_one_html_document(): void
    {
        $element = new class extends TextElement {
            public array $calls = [];
            public function viewData(array $data, EditorContext $context): array
            {
                $this->calls[] = [$data['geometry']['width'], $context->document->ordem, $context->preview, $context->data['label']];
                return [...$data, 'text' => $context->data['label']];
            }
        };
        $directory = sys_get_temp_dir().'/canvas-api-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory);
        File::put($directory.'/page.blade.php', '<section class="page" data-order="{{ $context->document->ordem }}">{!! $blocks !!}</section>');
        view()->addNamespace('render-api', $directory);
        $pages = [];
        foreach ([20 => 'Frozen A', 70 => 'Frozen B'] as $order => $label) {
            $owner = new class extends Model implements CanvasRenderData {
                public function canvasRenderData(CanvasDocument $document, EditorContext $context): array
                {
                    return ['label' => $this->label];
                }
            };
            $owner->ordem = $order;
            $owner->label = $label;
            $context = new EditorContext(new DocumentReference('test', $order), document: $owner);
            $document = new CanvasDocument([['type' => 'text', 'data' => ['width_pt' => 120, 'properties' => ['text' => 'original']]]]);
            $pages[] = new CanvasPage($document, $context);
        }
        try {
            $renderer = new CanvasRenderer([$element], 'render-api::page');
            $html = $renderer->renderPages($pages);
            $this->assertSame(1, substr_count($html, '<!doctype html>'));
            $this->assertSame(2, substr_count($html, '<section class="page"'));
            $this->assertStringContainsString('data-order="20"', $html);
            $this->assertStringContainsString('data-order="70"', $html);
            $this->assertStringContainsString('Frozen A', $html);
            $this->assertStringContainsString('Frozen B', $html);
            $this->assertSame([[120.0, 20, false, 'Frozen A'], [120.0, 70, false, 'Frozen B']], $element->calls);
            $this->assertSame('original', $pages[0]->document->blocks[0]['data']['properties']['text']);
            $renderer->render($pages[0]->document, $pages[0]->context);
            $this->assertTrue($element->calls[2][2]);
            $this->assertFalse($pages[0]->context->preview);
            $this->assertSame([], $pages[0]->context->data);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_empty_page_collections_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CanvasRenderer([TextElement::class]))->renderPages([]);
    }
}
