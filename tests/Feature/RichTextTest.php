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
    public function test_legacy_plain_text_is_ready_for_the_native_editor_without_a_false_formatting_change(): void
    {
        $dados = (new TextElement())->normalize(['text' => 'Texto importado']);
        $this->assertSame('Texto importado', $dados['text']);
        $this->assertSame('doc', $dados['rich_text']['type']);
        $this->assertSame('center', $dados['rich_text']['content'][0]['attrs']['textAlign']);
        $this->assertSame($dados, (new TextElement())->normalize($dados));
    }
    public function test_text_formatting_is_only_exposed_through_native_rich_editor_tools(): void
    {
        $host = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;
            public array $dados = [];
        };
        $element = new TextElement();
        $schema = $element->properties(Schema::make($host)->statePath('dados'));
        $schema->fill();
        $field = $schema->getComponentByStatePath('rich_text');
        foreach (['align', 'bold', 'italic'] as $property) {
            $this->assertNull($schema->getComponentByStatePath($property));
            $this->assertArrayNotHasKey($property, $host->dados);
        }
        foreach (['bold', 'italic', 'alignStart', 'alignCenter', 'alignEnd', 'alignJustify'] as $tool) {
            $this->assertContains($tool, Arr::flatten($field->getToolbarButtons()));
        }
    }
    public function test_legacy_global_formatting_moves_into_rich_nodes_without_losing_partial_marks(): void
    {
        $document = $this->richTextDocument();
        $document['content'][] = ['type' => 'paragraph', 'attrs' => ['textAlign' => 'end'], 'content' => [['type' => 'text', 'text' => 'Segundo']]];
        $dados = (new TextElement())->normalize(['rich_text' => $document, 'bold' => 'true', 'italic' => true, 'align' => 'center']);
        $this->assertSame('center', $dados['rich_text']['content'][0]['attrs']['textAlign']);
        $this->assertSame('end', $dados['rich_text']['content'][1]['attrs']['textAlign']);
        $this->assertSame(['bold', 'italic'], array_column($dados['rich_text']['content'][0]['content'][0]['marks'], 'type'));
        $this->assertSame(['bold', 'underline', 'highlight', 'italic'], array_column($dados['rich_text']['content'][0]['content'][1]['marks'], 'type'));
        foreach (['align', 'bold', 'italic'] as $property) {
            $this->assertArrayNotHasKey($property, $dados);
        }
        $this->assertSame($dados, (new TextElement())->normalize($dados));
    }
    public function test_titles_seed_bold_marks_and_native_edits_can_remove_them_permanently(): void
    {
        $host = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;
            public array $dados = [];
        };
        $element = new TitleElement();
        $schema = $element->properties(Schema::make($host)->statePath('dados'));
        $schema->fill();
        $dados = $element->normalize($host->dados);
        $this->assertSame([['type' => 'bold']], $dados['rich_text']['content'][0]['content'][0]['marks']);
        $this->assertArrayNotHasKey('bold', $dados);
        $legacy = $element->normalize(['text' => 'Título importado']);
        $this->assertStringContainsString('<strong>Título importado</strong>', $legacy['html']);
        $dados['rich_text']['content'][0]['content'][0]['marks'] = [];
        $dados = $element->normalize($dados);
        $this->assertStringNotContainsString('<strong>', $dados['html']);
        $this->assertSame($dados, $element->normalize($dados));
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
            public array $dados = [];
        };
        Schema::make($host)->statePath('dados')->components([$field])->fill();
        $this->assertSame([], $field->getToolbarButtons());
        $this->assertSame(['paragraph' => ['bold', 'underline', 'alignCenter'], 'heading' => ['bold', 'underline', 'alignCenter']], $field->getFloatingToolbars());
        $this->assertSame($field, $field->getTools()['link']->getEditor());
    }
    public function test_partial_rich_formatting_stays_authoritative_when_no_legacy_globals_remain(): void
    {
        foreach ([new TextElement(), new TitleElement()] as $element) {
            $dados = $element->normalize(['rich_text' => $this->richTextDocument(), 'bold' => false, 'italic' => false]);
            $this->assertArrayNotHasKey('marks', $dados['rich_text']['content'][0]['content'][0]);
            $this->assertSame(['bold', 'underline', 'highlight'], array_column($dados['rich_text']['content'][0]['content'][1]['marks'], 'type'));
        }
    }
    public function test_invalid_legacy_globals_are_still_rejected_after_normalization(): void
    {
        foreach ([['align' => 'sideways'], ['bold' => 'yes'], ['italic' => ['invalid']]] as $invalid) {
            try {
                $element = new TextElement();
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
        $this->assertSame('Texto destacado', $content['text']);
        $this->assertStringContainsString('<strong><u><mark>destacado</mark></u></strong>', $content['html']);
        $this->assertStringContainsString('Texto ', $content['html']);
        $this->assertStringNotContainsString('script', $content['html']);
        $this->assertStringNotContainsString('Untrusted', $content['html']);
        $this->assertSame($content['html'], RichText::html($content));
    }
    public function test_inline_html_is_converted_to_a_sanitized_tiptap_document(): void
    {
        $content = RichText::normalize(['html' => '<p onclick="alert(1)"><strong>A</strong> <em>B</em> <u>C</u> <s>D</s> <mark>E</mark> <a href="https://example.test">F</a><br><span style="color:#ff0000">G</span></p><ul><li><p>Item</p></li></ul>']);
        $this->assertSame('doc', $content['rich_text']['type']);
        foreach (['<strong>A</strong>', '<em>B</em>', '<u>C</u>', '<s>D</s>', '<mark>E</mark>', 'href="https://example.test"', '<ul>', '<li>', 'data-color="#ff0000"'] as $fragment) {
            $this->assertStringContainsString($fragment, $content['html']);
        }
        $this->assertStringNotContainsString('onclick', $content['html']);
        $this->assertStringContainsString('Item', $content['text']);
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
        $content = RichText::normalize(['html' => '<p><span style="color:#ff0000">A</span><mark data-color="#99ff00" style="background-color:#99ff00">B</mark><br>C</p><p>D</p>']);
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
        $this->assertFalse(RichText::hasFormatting($content));
        $this->assertSame(['text', 'hardBreak', 'hardBreak', 'text'], array_column(RichText::document($content)['content'][0]['content'], 'type'));
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
                $this->assertArrayHasKey('dados.rich_text', $exception->errors());
            }
        }
    }
    private function richTextDocument(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texto '], ['type' => 'text', 'text' => 'destacado', 'marks' => [['type' => 'bold'], ['type' => 'underline'], ['type' => 'highlight']]]]]]];
    }
}
