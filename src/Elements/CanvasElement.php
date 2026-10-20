<?php

namespace EduardoRibeiroDev\FilamentCanvas\Elements;

use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use EduardoRibeiroDev\FilamentCanvas\Contracts\HasElementMedia;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

abstract class CanvasElement implements ElementType, HasElementMedia
{
    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedSquare3Stack3d;
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function mediaCollections(): array
    {
        return [];
    }

    public function showInToolbar(): bool
    {
        return true;
    }

    public function normalize(array $properties): array
    {
        return $properties;
    }
}
