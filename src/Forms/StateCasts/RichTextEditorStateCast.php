<?php

namespace EduardoRibeiroDev\FilamentCanvas\Forms\StateCasts;

use EduardoRibeiroDev\FilamentCanvas\Support\RichText;
use Filament\Forms\Components\RichEditor\StateCasts\RichEditorStateCast;

class RichTextEditorStateCast extends RichEditorStateCast
{
    public function set(mixed $state): array
    {
        // Seed legacy content before Filament turns null into an empty document.
        // An existing document, including an intentionally empty one, stays authoritative.
        if ($state === null) {
            $get = $this->richEditor->getGetCallback();
            $content = array_filter([
                'html' => $get('html'), 'text' => $get('text'),
                'align' => $get('align') ?? 'center', 'bold' => $get('bold'), 'italic' => $get('italic'),
            ], fn ($value) => $value !== null);
            if ($content !== []) {
                $content['rich_text'] = RichText::document($content);
                $state = RichText::migrateTypography($content)['rich_text'];
            }
        }

        return parent::set($state);
    }
}
