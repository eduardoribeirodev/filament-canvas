<?php

namespace EduardoRibeiroDev\FilamentCanvas\Forms\Components;

use EduardoRibeiroDev\FilamentCanvas\Support\RichText;
use Filament\Forms\Components\RichEditor;
use Illuminate\Validation\ValidationException;

class RichTextEditor extends RichEditor
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->rule(fn () => function ($attribute, $value, $fail): void {
            try {
                RichText::document(['rich_text' => $value]);
            } catch (ValidationException $exception) {
                $fail('Invalid or oversized rich text document.');
            }
        });
    }
}
