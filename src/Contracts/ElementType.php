<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use Filament\Schemas\Schema;

interface ElementType
{
    public function type(): string;

    public function label(): string;

    public function icon(): string;

    /** Fields bind directly to the element's flat dados. Field defaults seed creation. */
    public function properties(Schema $schema): Schema;

    public function view(): string;

    /** Supplemental rules for internal/legacy data; native input rules come from properties(). */
    public function validationRules(string $prefix = ''): array;

    public function normalize(array $dados): array;

    /** Local editing capabilities, independent of inheritance or type names. */
    public function capabilities(): array;

    /** Escaped/sanitized inner HTML; the canvas wraps geometry. */
    public function render(array $block, EditorContext $context): string;
}
