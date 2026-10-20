<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\Elements\ElementGroup;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\LineElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use InvalidArgumentException;

class ElementGroupTest extends TestCase
{
    public function test_groups_preserve_flat_types_and_toolbar_order(): void
    {
        $registry = ElementRegistry::from([
            ElementGroup::make([TextElement::class, ImageElement::class])->label('Content'),
            LineElement::class,
            ElementGroup::make([])->label('Empty'),
            new TextElement,
        ]);
        $this->assertSame(['text', 'asset', 'line'], $registry->types());
        $this->assertSame([
            ['label' => 'Content', 'types' => ['text', 'asset'], 'grouped' => true],
            ['label' => null, 'types' => ['line'], 'grouped' => false],
        ], $registry->toolbarGroups());
        $this->assertSame(['label' => 'Content', 'types' => ['asset'], 'grouped' => true], $registry->only(['asset'])->toolbarGroups()[0]);
        $this->assertInstanceOf(TextElement::class, $registry->get('text'));
    }

    public function test_nested_groups_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ElementRegistry::from([ElementGroup::make([ElementGroup::make([TextElement::class])])]);
    }

    public function test_toolbar_escapes_labels_and_keeps_buttons_for_standalone_elements(): void
    {
        $registry = ElementRegistry::from([ElementGroup::make([TextElement::class])->label('<Content>'), ImageElement::class]);
        $html = view('filament-canvas::partials.canvas-toolbar-groups', [
            'elementGroups' => $registry->toolbarGroups(),
            'elementLabels' => $registry->labels(),
            'elementIcons' => $registry->icons(),
        ])->render();
        $this->assertStringContainsString('&lt;Content&gt;', $html);
        $this->assertStringNotContainsString('<Content>', $html);
        $this->assertStringContainsString('data-canvas-insert="text"', $html);
        $this->assertStringContainsString('data-canvas-insert="asset"', $html);
    }
}
