<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;

/** Optional owner data for a template, including the editor's unsaved composition. */
interface CanvasRenderData
{
    public function canvasRenderData(CanvasDocument $document, EditorContext $context): array;
}
