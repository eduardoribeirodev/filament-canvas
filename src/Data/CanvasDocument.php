<?php

namespace EduardoRibeiroDev\FilamentCanvas\Data;

final readonly class CanvasDocument
{
    /** @param array<int, array{type: string, data: array}> $blocks */
    public function __construct(
        public array $blocks,
        public float $width = 595.28,
        public float $height = 841.89,
        public string $revision = '',
        public array $metadata = [],
    ) {}

    public function withBlocks(array $blocks): self
    {
        return new self($blocks, $this->width, $this->height, $this->revision, $this->metadata);
    }
}
