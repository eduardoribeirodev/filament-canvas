<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use DOMDocument;
use DOMElement;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tiptap\Marks\Highlight;

/** A bounded, portable subset of Filament's RichEditor document format. */
final class RichText
{
    private const MAX_BYTES = 200000;

    private const MAX_TEXT = 20000;

    private const NODES = ['doc', 'paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'hardBreak', 'horizontalRule', 'codeBlock', 'text'];

    private const MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'link', 'textColor', 'subscript', 'superscript'];

    /** JSON is authoritative; HTML is an output cache, never trusted verbatim. */
    public static function normalize(array $content): array
    {
        if (! isset($content['rich_text']) && ! array_key_exists('html', $content)) {
            return $content;
        }

        $document = self::document($content);
        $renderer = self::renderer($document);
        $content['rich_text'] = $document;
        $content['html'] = $renderer->toHtml();
        $content['text'] = self::plainText($document);
        if (mb_strlen($content['text']) > self::MAX_TEXT) {
            self::invalid('O texto excede o limite permitido.');
        }

        return $content;
    }

    public static function normalizeBlocks(array $blocks): array
    {
        $blocks = ElementData::canonicalizeBlocks($blocks);
        foreach ($blocks as $index => $block) {
            if (is_array($block['data']['dados'] ?? null) && (isset($block['data']['dados']['rich_text']) || array_key_exists('html', $block['data']['dados']))) {
                $blocks[$index]['data']['dados'] = self::normalize($block['data']['dados']);
            }
        }

        return $blocks;
    }

    /** Move old element-wide formatting into the editor document once. */
    public static function migrateTypography(array $content, bool $title = false): array
    {
        $hasFormatting = self::hasFormatting($content);
        $legacy = array_intersect_key($content, array_flip(['align', 'bold', 'italic']));
        if (! $hasFormatting && ! $legacy && ! $title && ! array_key_exists('text', $content)) {
            return $content;
        }

        // Imports sometimes encoded booleans as the strings "true" and "false".
        foreach (['bold', 'italic'] as $key) {
            if (in_array($content[$key] ?? null, ['true', 'false'], true)) {
                $content[$key] = $content[$key] === 'true';
            }
        }

        $marks = [];
        foreach (['bold', 'italic'] as $key) {
            $value = $content[$key] ?? ($key === 'bold' && $title && ! $hasFormatting);
            if (in_array($value, [true, 1, '1'], true)) {
                $marks[] = $key;
            }
        }
        $align = match ($content['align'] ?? null) {
            'left' => 'start', 'right' => 'end', 'center', 'justify' => $content['align'], default => null,
        };
        if (! $hasFormatting && ! array_key_exists('align', $content)) {
            $align = 'center'; // Old plain canvas blocks inherited centered alignment.
        }
        $document = self::inheritTypography(self::document($content), $marks, $align);
        $content['rich_text'] = $document;

        // Invalid legacy values stay present so validation can report them.
        if ($align !== null || (($content['align'] ?? null) === null)) {
            unset($content['align']);
        }
        foreach (['bold', 'italic'] as $key) {
            if (! isset($content[$key]) || in_array($content[$key], [true, false, 0, 1, '0', '1'], true)) {
                unset($content[$key]);
            }
        }

        return self::normalize($content);
    }

    private static function inheritTypography(array $node, array $marks, ?string $align): array
    {
        if (($node['type'] ?? null) === 'text') {
            $existing = array_column($node['marks'] ?? [], 'type');
            foreach ($marks as $mark) {
                if (! in_array($mark, $existing, true)) {
                    $node['marks'][] = ['type' => $mark];
                }
            }
        }
        if ($align !== null && in_array($node['type'] ?? null, ['paragraph', 'heading'], true) && ! isset($node['attrs']['textAlign'])) {
            $node['attrs']['textAlign'] = $align;
        }
        if (isset($node['content'])) {
            $node['content'] = array_map(fn (array $child) => self::inheritTypography($child, $marks, $align), $node['content']);
        }

        return $node;
    }

    /** Also seeds the RichEditor from legacy plain text without changing stored data. */
    public static function document(array $content): array
    {
        if (isset($content['rich_text'])) {
            if (! is_array($content['rich_text'])) {
                self::invalid('Documento de texto inválido.');
            }

            return self::normalizeDocument($content['rich_text']);
        }
        if (array_key_exists('html', $content)) {
            if (! is_string($content['html']) || strlen($content['html']) > self::MAX_BYTES) {
                self::invalid('O texto formatado excede o limite permitido.');
            }
            $html = self::prepareHtml(Str::sanitizeHtml($content['html']));

            return self::normalizeDocument(self::renderer($html)->toArray() ?: ['type' => 'doc', 'content' => []]);
        }

        $text = $content['text'] ?? '';
        if (! is_string($text) || mb_strlen($text) > self::MAX_TEXT) {
            self::invalid('O texto excede o limite permitido.');
        }
        $nodes = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $index => $line) {
            if ($index) {
                $nodes[] = ['type' => 'hardBreak'];
            }
            if ($line !== '') {
                $nodes[] = ['type' => 'text', 'text' => $line];
            }
        }

        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $nodes]]];
    }

    /** Legacy plain text remains escaped, including its original whitespace. */
    public static function html(array $content): string
    {
        if (! isset($content['rich_text']) && ! array_key_exists('html', $content)) {
            return e($content['text'] ?? '');
        }

        return self::renderer(self::document($content))->toHtml();
    }

    public static function hasFormatting(array $content): bool
    {
        return isset($content['rich_text']) || array_key_exists('html', $content);
    }

    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'rich_text' => ['bail', 'nullable', 'array', function ($attribute, $value, $fail): void {
                try {
                    self::normalizeDocument($value);
                } catch (ValidationException $exception) {
                    $fail('Documento de texto inválido ou acima dos limites permitidos.');
                }
            }],
            $prefix.'html' => ['nullable', 'string', 'max:'.self::MAX_BYTES],
            $prefix.'text' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
        ];
    }

    /** Adjacent formatted spans must remain adjacent in the plain fallback. */
    private static function plainText(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return $node['text'];
        }
        if (($node['type'] ?? null) === 'hardBreak') {
            return "\n";
        }
        $separator = in_array($node['type'] ?? '', ['doc', 'blockquote', 'bulletList', 'orderedList', 'listItem'], true) ? "\n" : '';

        return implode($separator, array_map(self::plainText(...), $node['content'] ?? []));
    }

    /** Shared typography for canvas previews and printed catalogues. */
    public static function styles(): string
    {
        return '.canvas-rich-text{overflow-wrap:anywhere;white-space:pre-wrap}.canvas-rich-text p{margin:0}.canvas-rich-text p:empty{min-height:1em;min-height:1lh}.canvas-rich-text p+p{margin-top:.35em}.canvas-rich-text h1,.canvas-rich-text h2,.canvas-rich-text h3,.canvas-rich-text h4,.canvas-rich-text h5,.canvas-rich-text h6{margin:0;font-weight:700;line-height:1.2}.canvas-rich-text h1{font-size:2em}.canvas-rich-text h2{font-size:1.5em}.canvas-rich-text h3{font-size:1.17em}.canvas-rich-text ul,.canvas-rich-text ol{margin:.25em 0;padding-left:1.5em}.canvas-rich-text ul{list-style:disc}.canvas-rich-text ol{list-style:decimal}.canvas-rich-text li{display:list-item}.canvas-rich-text li p{margin:0}.canvas-rich-text blockquote{border-left:2pt solid #aaa;padding-left:.75em;margin:.25em 0}.canvas-rich-text mark{background:#ffeb3b;color:inherit}.canvas-rich-text .color{color:var(--color,inherit)}.canvas-rich-text a{color:inherit;text-decoration:underline}.canvas-rich-text code,.canvas-rich-text pre{font-family:monospace}.canvas-rich-text pre{margin:0;white-space:pre-wrap}.canvas-rich-text u{text-decoration:underline}.canvas-rich-text s{text-decoration:line-through}.canvas-rich-text hr{border:0;border-top:1pt solid #aaa}';
    }

    private static function renderer(array|string $content): RichContentRenderer
    {
        $renderer = new class($content) extends RichContentRenderer
        {
            public function getTipTapPhpExtensions(): array
            {
                return array_map(
                    fn ($extension) => $extension instanceof Highlight ? app(Highlight::class, ['options' => ['multicolor' => true]]) : $extension,
                    parent::getTipTapPhpExtensions(),
                );
            }
        };

        return $renderer->linkProtocols(['http', 'https', 'mailto', 'tel']);
    }

    private static function normalizeDocument(array $document): array
    {
        if (($document['type'] ?? null) !== 'doc' || strlen(json_encode($document, JSON_INVALID_UTF8_SUBSTITUTE) ?: '') > self::MAX_BYTES) {
            self::invalid('Documento de texto inválido ou acima do limite permitido.');
        }
        $count = 0;
        $textLength = 0;

        return self::node($document, 0, $count, $textLength);
    }

    private static function node(array $node, int $depth, int &$count, int &$textLength): array
    {
        $type = $node['type'] ?? null;
        if ($depth > 20 || ++$count > 2000 || ! in_array($type, self::NODES, true) || ($type === 'doc' && $depth !== 0)) {
            self::invalid('A estrutura do texto é inválida ou excede os limites permitidos.');
        }
        $result = ['type' => $type];
        $attrs = $node['attrs'] ?? [];
        if (! is_array($attrs)) {
            self::invalid('Atributos de texto inválidos.');
        }
        if (in_array($type, ['paragraph', 'heading'], true) && isset($attrs['textAlign'])) {
            if (! in_array($attrs['textAlign'], ['start', 'center', 'end', 'justify', 'left', 'right'], true)) {
                self::invalid('Alinhamento de texto inválido.');
            }
            $result['attrs']['textAlign'] = match ($attrs['textAlign']) {
                'left' => 'start', 'right' => 'end', default => $attrs['textAlign'],
            };
        }
        if ($type === 'heading') {
            $level = $attrs['level'] ?? 2;
            if (! is_int($level) || $level < 1 || $level > 6) {
                self::invalid('Nível de título inválido.');
            }
            $result['attrs']['level'] = $level;
        }
        if ($type === 'orderedList' && isset($attrs['start'])) {
            if (! is_int($attrs['start']) || $attrs['start'] < 1 || $attrs['start'] > 10000) {
                self::invalid('Numeração da lista inválida.');
            }
            $result['attrs']['start'] = $attrs['start'];
        }
        if ($type === 'text') {
            if (! is_string($node['text'] ?? null) || ($textLength += mb_strlen($node['text'])) > self::MAX_TEXT) {
                self::invalid('O texto excede o limite permitido.');
            }
            $result['text'] = $node['text'];
        }
        if (isset($node['marks'])) {
            if (! is_array($node['marks']) || count($node['marks']) > 12) {
                self::invalid('Formatação de texto inválida.');
            }
            $result['marks'] = [];
            foreach ($node['marks'] as $mark) {
                if (! is_array($mark)) {
                    self::invalid('Formatação de texto inválida.');
                }
                if (($normalized = self::mark($mark)) !== null) {
                    $result['marks'][] = $normalized;
                }
            }
        }
        if (isset($node['content'])) {
            if (! is_array($node['content']) || ! array_is_list($node['content']) || in_array($type, ['text', 'hardBreak', 'horizontalRule'], true)) {
                self::invalid('Conteúdo de texto inválido.');
            }
            $result['content'] = [];
            foreach ($node['content'] as $child) {
                if (! is_array($child)) {
                    self::invalid('Conteúdo de texto inválido.');
                }
                $result['content'][] = self::node($child, $depth + 1, $count, $textLength);
            }
        }

        return $result;
    }

    private static function mark(array $mark): ?array
    {
        $type = $mark['type'] ?? null;
        $attrs = $mark['attrs'] ?? [];
        if (! is_array($attrs)) {
            self::invalid('Formatação de texto inválida.');
        }
        if ($type === 'textStyle') {
            if (! isset($attrs['color'])) {
                return null;
            }
            $type = 'textColor';
            $attrs = ['data-color' => $attrs['color']];
        }
        if (! in_array($type, self::MARKS, true)) {
            self::invalid('Formatação de texto não suportada.');
        }
        $result = ['type' => $type];
        if ($type === 'link') {
            $href = $attrs['href'] ?? null;
            if (! is_string($href) || strlen($href) > 2048 || ! Str::sanitizeUrl($href, ['http', 'https', 'mailto', 'tel'])) {
                return null;
            }
            $result['attrs'] = ['href' => $href, 'target' => ($attrs['target'] ?? null) === '_blank' ? '_blank' : null, 'rel' => 'noopener noreferrer'];
        } elseif ($type === 'textColor') {
            $color = Str::sanitizeCssColor(is_string($attrs['data-color'] ?? null) ? $attrs['data-color'] : null);
            if (! $color) {
                return null;
            }
            $result['attrs'] = ['data-color' => $color];
        } elseif ($type === 'highlight') {
            $color = Str::sanitizeCssColor(is_string($attrs['color'] ?? null) ? $attrs['color'] : null);
            $result['attrs'] = ['color' => $color];
        }

        return $result;
    }

    /** Recognizes safe colored spans produced by direct canvas editing. */
    private static function prepareHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ($dom->getElementsByTagName('span') as $span) {
                if (! $span instanceof DOMElement) {
                    continue;
                }
                if (preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $span->getAttribute('style'), $match) && ($color = Str::sanitizeCssColor(trim($match[1])))) {
                    $span->setAttribute('class', 'color');
                    $span->setAttribute('data-color', $color);
                }
            }
            $body = $dom->getElementsByTagName('body')->item(0);
            $result = '';
            foreach ($body?->childNodes ?? [] as $child) {
                $result .= $dom->saveHTML($child);
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['dados.rich_text' => $message]);
    }
}
