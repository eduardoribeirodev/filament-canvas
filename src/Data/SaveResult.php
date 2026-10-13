<?php

namespace EduardoRibeiroDev\FilamentCanvas\Data;

final readonly class SaveResult
{
    public function __construct(public array $blocks, public string $revision) {}
}
