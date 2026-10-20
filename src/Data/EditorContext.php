<?php

namespace EduardoRibeiroDev\FilamentCanvas\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final readonly class EditorContext
{
    public function __construct(
        public DocumentReference $reference,
        public ?Authenticatable $user = null,
        public ?string $panelId = null,
        public ?Model $document = null,
    ) {}

    public function forDocument(Model $document): self
    {
        return new self($this->reference, $this->user, $this->panelId, $document);
    }
}
