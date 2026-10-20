<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\Elements\DataTableElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DrawingElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Filament\Schemas\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class TranslationsTest extends TestCase
{
    public static function locales(): array
    {
        return [
            ['en', 'Text', 'Font size', 'Entire image'],
            ['pt_BR', 'Texto', 'Tamanho da fonte', 'Imagem inteira'],
            ['pt_PT', 'Texto', 'Tamanho da letra', 'Imagem inteira'],
            ['es', 'Texto', 'Tamaño de fuente', 'Imagen completa'],
            ['fr', 'Texte', 'Taille de police', 'Image entière'],
            ['de', 'Text', 'Schriftgröße', 'Gesamtes Bild'],
            ['it', 'Testo', 'Dimensione carattere', 'Immagine intera'],
        ];
    }

    #[DataProvider('locales')]
    public function test_element_forms_use_the_current_locale(string $locale, string $text, string $fontSize, string $contain): void
    {
        app()->setLocale($locale);

        $element = new TextElement;
        $fields = $element->form(Schema::make())->getComponents();
        $this->assertSame($text, $element->getLabel());
        $this->assertSame($text, $fields[0]->getLabel());
        $this->assertSame($fontSize, $fields[1]->getLabel());

        $image = new ImageElement;
        $this->assertSame($contain, $image->form(Schema::make())->getComponents()[0]->getOptions()['contain']);
        $this->assertSame(__('filament-canvas::canvas.image'), $image->mediaCollections()['image']['label']);

        $drawing = (new DrawingElement)->form(Schema::make())->getComponents();
        $this->assertSame(__('filament-canvas::canvas.stroke_color'), $drawing[0]->getLabel());
        $this->assertSame(__('filament-canvas::canvas.dashed'), $drawing[2]->getOptions()['dashed']);

        $table = (new DataTableElement)->form(Schema::make())->getComponents();
        $this->assertSame(__('filament-canvas::canvas.columns'), $table[0]->getLabel());
        $this->assertSame(__('filament-canvas::canvas.column'), array_values($table[0]->getDefaultState())[0]['label']);
        $this->assertSame(__('filament-canvas::canvas.rows'), $table[1]->getLabel());
    }

    public function test_all_locales_have_complete_catalogs_and_matching_placeholders(): void
    {
        $root = dirname(__DIR__, 2).'/resources/lang';
        $english = require $root.'/en/canvas.php';
        foreach (self::locales() as [$locale]) {
            $catalog = require $root.'/'.$locale.'/canvas.php';
            $this->assertSame(array_keys($english), array_keys($catalog), $locale);
            foreach ($english as $key => $value) {
                $this->assertNotSame('', trim($catalog[$key]), $locale.':'.$key);
                preg_match_all('/:[a-z_]+/', $value, $expected);
                preg_match_all('/:[a-z_]+/', $catalog[$key], $actual);
                $this->assertEqualsCanonicalizing($expected[0], $actual[0], $locale.':'.$key);
                $this->assertSame($catalog[$key], trans('filament-canvas::canvas.'.$key, [], $locale));
            }
        }
    }

    public function test_unknown_locales_fall_back_to_english(): void
    {
        app()->setFallbackLocale('en');
        app()->setLocale('zz');

        $this->assertSame('Font size', (new TextElement)->form(Schema::make())->getComponents()[1]->getLabel());
    }
}
