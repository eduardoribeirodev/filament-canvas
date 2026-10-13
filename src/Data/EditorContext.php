<?php

namespace EduardoRibeiroDev\FilamentCanvas\Data;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class EditorContext
{
    public function __construct(
        public DocumentReference $reference,
        public ?Authenticatable $user = null,
        public ?string $panelId = null,
    ) {}
}
