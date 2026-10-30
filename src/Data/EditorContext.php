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
        public bool $preview = false,
        public array $data = [],
    ) {}

    public function forDocument(Model $document): self
    {
        return new self($this->reference, $this->user, $this->panelId, $document, $this->preview, $this->data);
    }
    public function forRendering(bool $preview, array $data): self
    {
        return new self($this->reference, $this->user, $this->panelId, $this->document, $preview, $data);
    }
}
