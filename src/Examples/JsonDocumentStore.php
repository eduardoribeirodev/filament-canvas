<?php

namespace EduardoRibeiroDev\FilamentCanvas\Examples;

use EduardoRibeiroDev\FilamentCanvas\Contracts\DocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Data\SaveResult;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementData;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/** Opt-in file example: define canvas-document Gate before using it in a panel. */
class JsonDocumentStore implements DocumentStore
{
    public function authorize(EditorContext $context, string $ability): void
    {
        app(Gate::class)->forUser($context->user)->authorize('canvas-document', [$context->reference, $ability]);
    }

    private function path(EditorContext $context): string
    {
        return storage_path('app/canvas/'.hash('sha256', $context->reference->type.':'.$context->reference->id).'.json');
    }

    private function read(string $path): CanvasDocument
    {
        $content = is_file($path) ? File::get($path) : null;
        $data = $content !== null ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
        $blocks = ElementData::canonicalizeBlocks($data['blocks'] ?? []);

        return new CanvasDocument($blocks, (float) ($data['width'] ?? 595.28), (float) ($data['height'] ?? 841.89), self::revision($blocks));
    }

    private static function revision(array $blocks): string
    {
        return hash('sha256', json_encode($blocks, JSON_THROW_ON_ERROR));
    }

    public function load(EditorContext $context): CanvasDocument
    {
        $this->authorize($context, 'view');

        return $this->read($this->path($context));
    }

    public function save(EditorContext $context, array $blocks, string $expectedRevision): SaveResult
    {
        $this->authorize($context, 'update');
        $blocks = ElementData::canonicalizeBlocks($blocks);
        $path = $this->path($context);
        File::ensureDirectoryExists(dirname($path));
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock the canvas document.');
        }
        try {
            $document = $this->read($path);
            if (! hash_equals($document->revision, $expectedRevision)) {
                throw ValidationException::withMessages(['blocks' => 'A composição foi alterada em outra janela. Recarregue antes de continuar.']);
            }
            $payload = json_encode(['blocks' => $blocks, 'width' => $document->width, 'height' => $document->height], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            File::replace($path, $payload);

            return new SaveResult($blocks, self::revision($blocks));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
