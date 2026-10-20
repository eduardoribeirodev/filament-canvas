<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

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
        if (! isset($content['rich_text'])) {
            return $content;
        }

        $document = self::document($content);
        $renderer = self::renderer($document);
        $content['rich_text'] = $document;
        $content['html'] = $renderer->toHtml();
        $content['text'] = self::plainText($document);
        if (mb_strlen($content['text']) > self::MAX_TEXT) {
            self::invalid(__('filament-canvas::canvas.text_limit'));
        }

        return $content;
    }

    public static function document(array $content): array
    {
        $document = $content['rich_text'] ?? ['type' => 'doc', 'content' => []];
        if (! is_array($document)) {
            self::invalid(__('filament-canvas::canvas.invalid_rich_text'));
        }

        return self::normalizeDocument($document);
    }

    public static function html(array $content): string
    {
        return isset($content['rich_text'])
            ? self::renderer(self::document($content))->toHtml()
            : e($content['text'] ?? '');
    }

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
            self::invalid(__('filament-canvas::canvas.oversized_rich_text'));
        }
        $count = 0;
        $textLength = 0;

        return self::node($document, 0, $count, $textLength);
    }

    private static function node(array $node, int $depth, int &$count, int &$textLength): array
    {
        $type = $node['type'] ?? null;
        if ($depth > 20 || ++$count > 2000 || ! in_array($type, self::NODES, true) || ($type === 'doc' && $depth !== 0)) {
            self::invalid(__('filament-canvas::canvas.invalid_text_structure'));
        }
        $result = ['type' => $type];
        $attrs = $node['attrs'] ?? [];
        if (! is_array($attrs)) {
            self::invalid(__('filament-canvas::canvas.invalid_text_attributes'));
        }
        if (in_array($type, ['paragraph', 'heading'], true) && isset($attrs['textAlign'])) {
            if (! in_array($attrs['textAlign'], ['start', 'center', 'end', 'justify', 'left', 'right'], true)) {
                self::invalid(__('filament-canvas::canvas.invalid_alignment'));
            }
            $result['attrs']['textAlign'] = match ($attrs['textAlign']) {
                'left' => 'start', 'right' => 'end', default => $attrs['textAlign'],
            };
        }
        if ($type === 'heading') {
            $level = $attrs['level'] ?? 2;
            if (! is_int($level) || $level < 1 || $level > 6) {
                self::invalid(__('filament-canvas::canvas.invalid_heading'));
            }
            $result['attrs']['level'] = $level;
        }
        if ($type === 'orderedList' && isset($attrs['start'])) {
            if (! is_int($attrs['start']) || $attrs['start'] < 1 || $attrs['start'] > 10000) {
                self::invalid(__('filament-canvas::canvas.invalid_numbering'));
            }
            $result['attrs']['start'] = $attrs['start'];
        }
        if ($type === 'text') {
            if (! is_string($node['text'] ?? null) || ($textLength += mb_strlen($node['text'])) > self::MAX_TEXT) {
                self::invalid(__('filament-canvas::canvas.text_limit'));
            }
            $result['text'] = $node['text'];
        }
        if (isset($node['marks'])) {
            if (! is_array($node['marks']) || count($node['marks']) > 12) {
                self::invalid(__('filament-canvas::canvas.invalid_formatting'));
            }
            $result['marks'] = [];
            foreach ($node['marks'] as $mark) {
                if (! is_array($mark)) {
                    self::invalid(__('filament-canvas::canvas.invalid_formatting'));
                }
                if (($normalized = self::mark($mark)) !== null) {
                    $result['marks'][] = $normalized;
                }
            }
        }
        if (isset($node['content'])) {
            if (! is_array($node['content']) || ! array_is_list($node['content']) || in_array($type, ['text', 'hardBreak', 'horizontalRule'], true)) {
                self::invalid(__('filament-canvas::canvas.invalid_content'));
            }
            $result['content'] = [];
            foreach ($node['content'] as $child) {
                if (! is_array($child)) {
                    self::invalid(__('filament-canvas::canvas.invalid_content'));
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
            self::invalid(__('filament-canvas::canvas.invalid_formatting'));
        }
        if ($type === 'textStyle') {
            if (! isset($attrs['color'])) {
                return null;
            }
            $type = 'textColor';
            $attrs = ['data-color' => $attrs['color']];
        }
        if (! in_array($type, self::MARKS, true)) {
            self::invalid(__('filament-canvas::canvas.unsupported_formatting'));
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
    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['properties.rich_text' => $message]);
    }
}
