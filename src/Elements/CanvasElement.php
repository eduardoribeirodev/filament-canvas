<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use Filament\Schemas\Schema;

abstract class CanvasElement implements ElementType
{
    public function icon(): string
    {
        return 'heroicon-o-square-3-stack-3d';
    }

    public function properties(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function capabilities(): array
    {
        return [];
    }

    public function normalize(array $dados): array
    {
        return $dados;
    }

    public function validationRules(string $prefix = ''): array
    {
        return [
            $prefix.'link_url' => ['nullable', 'url:http,https', 'max:2048'],
            $prefix.'opacity' => ['nullable', 'numeric', 'between:0,1'],
            $prefix.'blend_mode' => ['nullable', 'in:normal,multiply,screen'],
        ];
    }

    protected function typographyRules(string $prefix = ''): array
    {
        return [
            $prefix.'font_size' => ['nullable', 'numeric', 'between:1,100'],
            $prefix.'color' => ['nullable', 'regex:/^#(?:[a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})$/iD'],
            $prefix.'align' => ['nullable', 'in:left,center,right,justify'],
            $prefix.'font' => ['nullable', 'string', 'max:100'],
            $prefix.'bold' => ['nullable', 'boolean'],
            $prefix.'italic' => ['nullable', 'boolean'],
        ];
    }

    /** Add application data to the view without copying it into the saved JSON. */
    public function viewData(array $dados, EditorContext $context, array $media = []): array
    {
        return [];
    }

    public function render(array $block, EditorContext $context): string
    {
        $dados = ElementData::from($block['data'] ?? []);
        $media = $this instanceof HasElementMedia ? $this->resolveMedia($block, $context) : [];

        return view($this->view(), array_merge($this->viewData($dados, $context, $media), [
            'dados' => $dados,
            'context' => $context,
            'media' => $media,
            'block' => $block,
        ]))->render();
    }
}
