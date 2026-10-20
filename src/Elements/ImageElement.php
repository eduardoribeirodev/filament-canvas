<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ImageElement extends CanvasElement
{
    public function __construct(protected string $name = 'asset') {}

    public function type(): string
    {
        return $this->name;
    }

    public function getLabel(): string|Htmlable|null
    {
        return __('filament-canvas::canvas.image');
    }

    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedPhoto;
    }

    public function showInToolbar(): bool
    {
        return $this->name === 'asset';
    }

    public function view(): string
    {
        return 'filament-canvas::elements.image';
    }

    public function mediaCollections(): array
    {
        return ['image' => [
            'label' => __('filament-canvas::canvas.image'),
            'accepted_mime_types' => ['image/png', 'image/jpeg', 'image/webp'],
            'max_files' => 1,
            'max_size' => 10240,
            'reference_field' => 'image_id',
        ]];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('fit')
                ->label(__('filament-canvas::canvas.image_fit'))
                ->options([
                    'contain' => __('filament-canvas::canvas.contain'),
                    'cover' => __('filament-canvas::canvas.cover'),
                    'fill' => __('filament-canvas::canvas.fill'),
                ])
                ->default('contain')
                ->native(false),
            TextInput::make('opacity')
                ->label(__('filament-canvas::canvas.opacity'))
                ->numeric()
                ->minValue(0)
                ->maxValue(1)
                ->step(.05)
                ->default(1),
            Toggle::make('flip_x')
                ->label(__('filament-canvas::canvas.flip_x'))
                ->default(false),
            Toggle::make('flip_y')
                ->label(__('filament-canvas::canvas.flip_y'))
                ->default(false),
            Select::make('blend_mode')
                ->label(__('filament-canvas::canvas.blend_mode'))
                ->options([
                    'normal' => __('filament-canvas::canvas.normal'),
                    'multiply' => __('filament-canvas::canvas.multiply'),
                    'screen' => __('filament-canvas::canvas.screen'),
                ])
                ->default('normal')
                ->native(false),
            TextInput::make('link_url')
                ->label(__('filament-canvas::canvas.link'))
                ->url()
                ->maxLength(2048),
        ])->columns(2);
    }
}
