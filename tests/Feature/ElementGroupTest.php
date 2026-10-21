<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\Elements\ElementGroup;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\LineElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Support\CanvasElements;
use EduardoRibeiroDev\FilamentCanvas\Tests\Support\TestDocument;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use InvalidArgumentException;

class ElementGroupTest extends TestCase
{
    public function test_groups_preserve_flat_types_and_toolbar_order(): void
    {
        $definitions = CanvasElements::list([
            ElementGroup::make([TextElement::class, ImageElement::class])->label('Content'),
            LineElement::class,
            ElementGroup::make([])->label('Empty'),
            new TextElement,
        ]);
        $this->assertSame(['text', 'asset', 'line'], CanvasElements::types($definitions));
        $this->assertSame([
            ['label' => 'Content', 'types' => ['text', 'asset'], 'grouped' => true],
            ['label' => null, 'types' => ['line'], 'grouped' => false],
        ], CanvasElements::toolbarGroups($definitions));
        $this->assertSame(['label' => 'Content', 'types' => ['asset'], 'grouped' => true], CanvasElements::toolbarGroups(CanvasElements::only($definitions, ['asset']))[0]);
        $this->assertInstanceOf(TextElement::class, CanvasElements::resolve($definitions, 'text'));
    }

    public function test_nested_groups_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CanvasElements::list([ElementGroup::make([ElementGroup::make([TextElement::class])])]);
    }

    public function test_toolbar_escapes_labels_and_keeps_buttons_for_standalone_elements(): void
    {
        $definitions = CanvasElements::list([ElementGroup::make([TextElement::class])->label('<Content>'), ImageElement::class]);
        $html = view('filament-canvas::partials.canvas-toolbar-groups', [
            'elementGroups' => CanvasElements::toolbarGroups($definitions),
            'elementLabels' => CanvasElements::labels($definitions),
            'elementIcons' => CanvasElements::icons($definitions),
        ])->render();
        $this->assertStringContainsString('&lt;Content&gt;', $html);
        $this->assertStringNotContainsString('<Content>', $html);
        $this->assertStringContainsString('data-canvas-insert="text"', $html);
        $this->assertStringContainsString('data-canvas-insert="asset"', $html);
    }

    public function test_resolution_uses_each_document_instances_available_list(): void
    {
        $owner = new class extends TestDocument
        {
            public array $choices = [];

            public function canvasElementsAvailable(): ?array
            {
                return $this->choices;
            }
        };
        $owner->choices = [ElementGroup::make([TextElement::class])];
        $other = clone $owner;
        $other->choices = [ImageElement::class];
        $this->assertInstanceOf(TextElement::class, $owner->resolveCanvasElement('text'));
        $this->assertInstanceOf(ImageElement::class, $other->resolveCanvasElement('asset'));
        $this->expectException(InvalidArgumentException::class);
        $owner->resolveCanvasElement('asset');
    }
}
