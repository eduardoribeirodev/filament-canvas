@php
    use EduardoRibeiroDev\FilamentCanvas\Rendering\Html;
    $width = max(.1, (float) ($block['data']['largura_pt'] ?? 1));
    $height = max(.1, (float) ($block['data']['altura_pt'] ?? 1));
    $x1 = max(0, min(100, (float) ($drawing['x1'] ?? 0))) * $width / 100;
    $x2 = max(0, min(100, (float) ($drawing['x2'] ?? 100))) * $width / 100;
    $y1 = max(0, min(100, (float) ($drawing['y1'] ?? 50))) * $height / 100;
    $y2 = max(0, min(100, (float) ($drawing['y2'] ?? 50))) * $height / 100;
    $stroke = Html::color($drawing['stroke'] ?? '#E60014', '#E60014');
    $weight = max(.1, min(20, (float) ($drawing['stroke_width'] ?? .7)));
    $gap = max(.1, min(100, (float) ($drawing['gap_length'] ?? 4.25)));
    $dash = max(.1, min(100, (float) ($drawing['dash_length'] ?? 4.25)));
    $pattern = $drawing['pattern'] ?? 'solid';
    $dashes = $pattern === 'dotted' ? '0 '.$gap : ($pattern === 'dashed' ? $dash.' '.$gap : 'none');
@endphp
<svg class="drawing" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" aria-hidden="true"><line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" stroke="{{ $stroke }}" stroke-width="{{ $weight }}" stroke-dasharray="{{ $dashes }}" stroke-linecap="{{ $pattern === 'dotted' ? 'round' : 'butt' }}" fill="none" /></svg>
