<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use Filament\Schemas\Schema;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

interface ElementType extends HasIcon, HasLabel
{
    public function type(): string;

    /** Fields bind directly to the element's flat properties. Field defaults seed creation. */
    public function form(Schema $schema): Schema;

    public function view(): string;
}
