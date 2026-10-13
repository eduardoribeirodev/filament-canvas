<div class="image-frame">
    @if (($media['image']['image'] ?? '') !== '')
        <img class="image" src="{{ $media['image']['image'] }}" alt="{{ $media['image']['name'] ?? '' }}">
    @else
        <span class="missing">Selecione uma imagem</span>
    @endif
</div>
