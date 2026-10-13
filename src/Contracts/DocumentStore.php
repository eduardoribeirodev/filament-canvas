<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Data\SaveResult;

interface DocumentStore
{
    /** Authorize the actor and document for view, preview, or update. */
    public function authorize(EditorContext $context, string $ability): void;

    public function load(EditorContext $context): CanvasDocument;

    /** Compare revision and persist inside the same transaction/lock. */
    public function save(EditorContext $context, array $blocks, string $expectedRevision): SaveResult;
}
