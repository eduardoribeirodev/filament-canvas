<?php

namespace EduardoRibeiroDev\FilamentCanvas\Data;

/** A page's native document and its owner/user context, including frozen publication owners. */
final readonly class CanvasPage
{
    public function __construct(public CanvasDocument $document, public EditorContext $context) {}
}
