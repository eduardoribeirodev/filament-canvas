<?php

namespace EduardoRibeiroDev\FilamentCanvas\Pages;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Schemas\Components\Canvas;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;

/** The Resource provides the owner model; every editor option is optional. */
abstract class EditCanvas extends Page
{
    protected string $view = 'filament-canvas::pages.edit-canvas';

    protected static string $documentType;

    protected static ?string $canvasTemplate = null;

    protected static ?array $canvasElements = null;

    protected static ?array $canvasExtraElements = null;

    protected static ?string $canvasEditorView = null;

    public static function getCanvasDocumentType(): string
    {
        return static::$documentType ?? static::getResource()::getModel();
    }

    public static function getCanvasOptions(): array
    {
        return array_filter([
            'model' => static::getResource()::getModel(),
            'template' => static::$canvasTemplate, 'elements' => static::$canvasElements,
            'extraElements' => static::$canvasExtraElements,
            'editorView' => static::$canvasEditorView,
        ], fn ($value) => $value !== null);
    }

    public static function getCanvasPlugin(?string $panelId = null): CanvasPlugin
    {
        $panel = $panelId !== null ? Filament::getPanel($panelId) : (Filament::getCurrentPanel() ?? Filament::getDefaultPanel());

        return $panel->getPlugin('filament-canvas')->forModel(static::getCanvasDocumentType(), static::getResource()::getModel(), static::getCanvasOptions());
    }

    public static function editorParameters(string|int $id): array
    {
        return Canvas::make()->document(static::getCanvasDocumentType(), $id)
            ->options(static::getCanvasOptions())->getEditorParameters();
    }

    protected static ?string $title = 'Visual editor';

    protected \Filament\Support\Enums\Width|string|null $maxContentWidth = 'full';

    #[Locked]
    public string $documentId;

    public function getHeading(): ?string
    {
        return null;
    }

    public function mount(int|string $record): void
    {
        $this->documentId = (string) $record;
        $panel = Filament::getCurrentPanel();
        $context = new EditorContext(new DocumentReference(static::getCanvasDocumentType(), $record), Filament::auth()->user(), $panel->getId());
        static::getCanvasPlugin($panel->getId())->resolveDocumentStore(static::getCanvasDocumentType())->authorize($context, 'update');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([Canvas::make()->document(static::getCanvasDocumentType(), fn () => $this->documentId)
            ->options(static::getCanvasOptions())->columnSpanFull()]);
    }
}
