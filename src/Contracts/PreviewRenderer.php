<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;

interface PreviewRenderer
{
    /** Return trusted HTML with .page and .block[data-editor-index] hooks. */
    public function render(CanvasDocument $document, EditorContext $context): string;
}
