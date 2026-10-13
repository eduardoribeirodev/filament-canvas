@php
    use EduardoRibeiroDev\FilamentCanvas\Rendering\Html;
    use EduardoRibeiroDev\FilamentCanvas\Support\RichText;
    $background = Html::color($dados['background_color'] ?? '', 'transparent');
    $padding = max(0, min(100, (float) ($dados['padding'] ?? 0)));
    $radius = max(0, min(100, (float) ($dados['border_radius'] ?? 0)));
    $hasRichText = RichText::hasFormatting($dados);
    $weight = ! $hasRichText && ($dados['bold'] ?? (($block['type'] ?? 'text') === 'title')) ? 700 : 400;
    $italic = ! $hasRichText && ($dados['italic'] ?? false) ? 'italic' : 'normal';
    $leading = max(.5, min(10, (float) ($dados['leading'] ?? 1.25)));
    $columns = max(1, min(12, (int) ($dados['columns'] ?? 1)));
@endphp
<div class="text-content {{ $hasRichText ? 'canvas-rich-text' : '' }}" style="font-weight:{{ $weight }};font-style:{{ $italic }};line-height:{{ $leading }};column-count:{{ $columns }};background:{{ $background }};padding:{{ $padding }}pt;border-radius:{{ $radius }}pt">{!! RichText::html($dados) !!}</div>
