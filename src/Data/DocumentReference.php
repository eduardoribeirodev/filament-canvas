<?php

namespace EduardoRibeiroDev\FilamentCanvas\Data;

final readonly class DocumentReference
{
    public function __construct(public string $type, public string|int $id) {}
}
