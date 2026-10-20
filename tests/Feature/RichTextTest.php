<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TitleElement;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementValidation;
use EduardoRibeiroDev\FilamentCanvas\Support\RichText;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class RichTextTest extends TestCase
{
    public function test_text_formatting_is_only_exposed_through_native_rich_editor_tools(): void
    {
        $host = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $properties = [];
        };
        $element = new TextElement;
        $schema = $element->form(Schema::make($host)->statePath('properties'));
        $schema->fill();
        $field = $schema->getComponentByStatePath('rich_text');
        foreach (['align', 'bold', 'italic'] as $property) {
            $this->assertNull($schema->getComponentByStatePath($property));
            $this->assertArrayNotHasKey($property, $host->properties);
        }
        foreach (['bold', 'italic', 'alignStart', 'alignCenter', 'alignEnd', 'alignJustify'] as $tool) {
            $this->assertContains($tool, Arr::flatten($field->getToolbarButtons()));
        }
    }

    public function test_titles_seed_bold_marks_and_native_edits_can_remove_them_permanently(): void
    {
        $host = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $properties = [];
        };
        $element = new TitleElement;
        $schema = $element->form(Schema::make($host)->statePath('properties'));
        $schema->fill();
        $properties = $element->normalize($host->properties);
        $this->assertSame([['type' => 'bold']], $properties['rich_text']['content'][0]['content'][0]['marks']);
        $this->assertArrayNotHasKey('bold', $properties);
        $properties['rich_text']['content'][0]['content'][0]['marks'] = [];
        $properties = $element->normalize($properties);
        $this->assertStringNotContainsString('<strong>', $properties['html']);
        $this->assertSame($properties, $element->normalize($properties));
    }

    public function test_inline_editor_reuses_custom_native_tools_in_floating_toolbars(): void
    {
        $element = new class extends TextElement
        {
            protected function textField(): RichEditor
            {
                return parent::textField()->toolbarButtons([['bold', 'underline'], ['alignCenter']]);
            }
        };
        $field = $element->inlineEditorField();
        $host = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $properties = [];
        };
        Schema::make($host)->statePath('properties')->components([$field])->fill();
        $this->assertSame([], $field->getToolbarButtons());
        $this->assertSame(['paragraph' => ['bold', 'underline', 'alignCenter'], 'heading' => ['bold', 'underline', 'alignCenter']], $field->getFloatingToolbars());
        $this->assertSame($field, $field->getTools()['link']->getEditor());
    }

    public function test_partial_rich_formatting_is_preserved_when_normalizing(): void
    {
        foreach ([new TextElement, new TitleElement] as $element) {
            $properties = $element->normalize(['rich_text' => $this->richTextDocument()]);
            $this->assertArrayNotHasKey('marks', $properties['rich_text']['content'][0]['content'][0]);
            $this->assertSame(['bold', 'underline', 'highlight'], array_column($properties['rich_text']['content'][0]['content'][1]['marks'], 'type'));
        }
    }

    public function test_removed_typography_properties_are_rejected(): void
    {
        foreach ([['align' => 'sideways'], ['bold' => 'yes'], ['italic' => ['invalid']]] as $invalid) {
            try {
                $element = new TextElement;
                ElementValidation::validate($element, $element->normalize(['text' => 'Original', ...$invalid]));
                $this->fail('Invalid old typography values must not disappear before validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(array_key_first($invalid), $exception->errors());
            }
        }
    }

    public function test_json_preserves_partial_formatting_and_rebuilds_the_html_cache(): void
    {
        $content = RichText::normalize(['rich_text' => $this->richTextDocument(), 'html' => '<script>alert(1)</script>', 'text' => 'Untrusted fallback']);
        $this->assertSame('Text destacado', $content['text']);
        $this->assertStringContainsString('<strong><u><mark>destacado</mark></u></strong>', $content['html']);
        $this->assertStringContainsString('Text ', $content['html']);
        $this->assertStringNotContainsString('script', $content['html']);
        $this->assertStringNotContainsString('Untrusted', $content['html']);
        $this->assertSame($content['html'], RichText::html($content));
    }

    public function test_unsafe_links_and_attributes_never_reach_rendered_content(): void
    {
        $document = $this->richTextDocument();
        $document['content'][0]['content'][1]['marks'][] = ['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)', 'onclick' => 'alert(2)']];
        $document['content'][0]['attrs'] = ['onclick' => 'alert(3)', 'style' => 'position:fixed'];
        $html = RichText::normalize(['rich_text' => $document])['html'];
        foreach (['javascript', 'onclick', 'position:fixed', '<a '] as $fragment) {
            $this->assertStringNotContainsString($fragment, $html);
        }
    }

    public function test_text_colors_highlights_and_inline_breaks_remain_portable(): void
    {
        $content = RichText::normalize(['rich_text' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A', 'marks' => [['type' => 'textColor', 'attrs' => ['data-color' => '#ff0000']]]], ['type' => 'text', 'text' => 'B', 'marks' => [['type' => 'highlight', 'attrs' => ['color' => '#99ff00']]]], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'C']]], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'D']]]]]]);
        $this->assertSame("AB\nC\nD", $content['text']);
        $this->assertStringContainsString('data-color="#ff0000"', $content['html']);
        $this->assertStringContainsString('background-color: #99ff00', $content['html']);
        $this->assertStringContainsString('color:var(--color,inherit)', RichText::styles());
    }

    public function test_unformatted_text_keeps_its_original_whitespace_and_is_escaped(): void
    {
        $content = ['text' => "  A < B\n\nC & D  ", 'field' => 'name'];
        $this->assertSame($content, RichText::normalize($content));
        $this->assertSame("  A &lt; B\n\nC &amp; D  ", RichText::html($content));
    }

    public function test_empty_rich_paragraphs_keep_their_lines_for_printing(): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A']]], ['type' => 'paragraph'], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'B']]]]];
        $content = RichText::normalize(['rich_text' => $document]);
        $this->assertSame("A\n\nB", $content['text']);
        $this->assertSame('<p>A</p><p></p><p>B</p>', $content['html']);
        $this->assertStringContainsString('p:empty{min-height:1em;min-height:1lh}', RichText::styles());
    }

    public function test_deep_text_documents_are_rejected_before_rendering(): void
    {
        $node = ['type' => 'paragraph', 'content' => []];
        for ($level = 0; $level < 21; $level++) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }
        $this->expectException(ValidationException::class);
        RichText::normalize(['rich_text' => ['type' => 'doc', 'content' => [$node]]]);
    }

    public function test_oversized_text_and_embedded_media_are_rejected(): void
    {
        foreach ([['type' => 'image', 'attrs' => ['id' => '../secret']], ['type' => 'text', 'text' => str_repeat('x', 20001)]] as $node) {
            try {
                RichText::normalize(['rich_text' => ['type' => 'doc', 'content' => [$node]]]);
                $this->fail('Unsupported or oversized text must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('properties.rich_text', $exception->errors());
            }
        }
    }

    private function richTextDocument(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Text '], ['type' => 'text', 'text' => 'destacado', 'marks' => [['type' => 'bold'], ['type' => 'underline'], ['type' => 'highlight']]]]]]];
    }
}
