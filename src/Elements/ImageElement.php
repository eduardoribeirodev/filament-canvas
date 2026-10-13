<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use EduardoRibeiroDev\FilamentCanvas\Contracts\AssetLibrary;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Rendering\Html;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ImageElement extends CanvasElement implements HasElementMedia
{
    public function __construct(protected string $name = 'asset', protected ?AssetLibrary $library = null) {}

    public function type(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'Imagem';
    }

    public function icon(): string
    {
        return 'heroicon-o-photo';
    }

    public function capabilities(): array
    {
        return ['image' => true, 'media' => true, 'toolbar' => $this->name === 'asset'];
    }

    public function view(): string
    {
        return 'filament-canvas::elements.image';
    }

    public function mediaCollections(): array
    {
        return ['image' => [
            'label' => 'Imagem', 'accepted_mime_types' => ['image/png', 'image/jpeg', 'image/webp'],
            'max_files' => 1, 'max_size' => 10240, 'reference_field' => 'imagem_origem',
        ]];
    }

    public function properties(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('fit')->label('Enquadramento')->options(['contain' => 'Imagem inteira', 'cover' => 'Preencher'])
                ->default('contain')->native(false),
            TextInput::make('opacity')->label('Opacidade')->numeric()->minValue(0)->maxValue(1)->step(.05)->default(1),
            Toggle::make('flip_x')->label('Espelhar horizontalmente')->default(false),
            Toggle::make('flip_y')->label('Espelhar verticalmente')->default(false),
            Select::make('blend_mode')->label('Mesclagem')->options(['normal' => 'Normal', 'multiply' => 'Multiplicar', 'screen' => 'Clarear'])
                ->default('normal')->native(false),
            TextInput::make('link_url')->label('Link')->url()->maxLength(2048),
        ])->columns(2);
    }

    public function validationRules(string $prefix = ''): array
    {
        return [
            ...parent::validationRules($prefix),
            $prefix.'crop_x' => ['nullable', 'numeric', 'between:0,1'],
            $prefix.'crop_y' => ['nullable', 'numeric', 'between:0,1'],
            $prefix.'image_transform' => ['sometimes', 'nullable', 'array:x,y,width,height,rotation'],
            $prefix.'image_transform.x' => ['required_with:'.$prefix.'image_transform', 'numeric', 'between:-10000,10000'],
            $prefix.'image_transform.y' => ['required_with:'.$prefix.'image_transform', 'numeric', 'between:-10000,10000'],
            $prefix.'image_transform.width' => ['required_with:'.$prefix.'image_transform', 'numeric', 'gt:0', 'max:10000'],
            $prefix.'image_transform.height' => ['required_with:'.$prefix.'image_transform', 'numeric', 'gt:0', 'max:10000'],
            $prefix.'image_transform.rotation' => ['required_with:'.$prefix.'image_transform', 'numeric', 'between:-360,360'],
        ];
    }

    public function withAssetLibrary(AssetLibrary $library): static
    {
        $element = clone $this;
        $element->library ??= $library;

        return $element;
    }

    public function resolveMedia(array $block, EditorContext $context): array
    {
        $data = $block['data'] ?? [];
        $assets = [];
        foreach ($this->library?->assets($context) ?? [] as $asset) {
            $assets[(string) ($asset['id'] ?? '')] = $asset;
        }
        $media = [];
        foreach ($this->mediaCollections() as $name => $collection) {
            $reference = data_get($data, $collection['reference_field'] ?? 'imagem_origem');
            $multiple = (int) ($collection['max_files'] ?? 1) > 1;
            $references = $multiple ? (is_array($reference) ? $reference : []) : [$reference];
            $resolved = [];
            foreach ($references as $id) {
                if (! is_string($id) && ! is_int($id)) {
                    continue;
                }
                $asset = $assets[(string) $id] ?? null;
                if ($asset !== null) {
                    $asset['image'] = Html::rawImageUrl($asset['image'] ?? '');
                    $resolved[] = $asset;
                }
            }
            if ($multiple) {
                $media[$name] = $resolved;

                continue;
            }
            $media[$name] = $resolved[0] ?? ['id' => $reference, 'image' => '', 'name' => ''];
            if ($name === 'image' && $resolved === []) {
                $media[$name]['image'] = Html::rawImageUrl($data['imagem_url'] ?? '');
            }
        }

        return $media;
    }
}
