<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use App\Canvas\Elements\GeneratedNoticeElement;
use App\Canvas\Elements\InteractiveQuoteElement;
use App\Canvas\Elements\Marketing\PromoCardElement;
use App\Canvas\Elements\PromptedQuoteElement;
use Company\Canvas\Elements\BannerElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DataTableElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DrawingElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TitleElement;
use EduardoRibeiroDev\FilamentCanvas\Rendering\ElementMarkup;
use EduardoRibeiroDev\FilamentCanvas\Support\CanvasElements;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementValidation;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class MakeCanvasElementCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/canvas-generator-'.bin2hex(random_bytes(8));
        $this->app->getNamespace();
        $this->app->setBasePath($this->directory);
        $this->app->useAppPath($this->directory.'/app');
        $this->app['view']->addLocation(resource_path('views'));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_generated_element_registers_renders_and_validates_its_form(): void
    {
        $this->artisan('make:canvas-element', ['name' => 'generated_notice_element'])
            ->expectsQuestion('Which built-in element would you like to extend?', 'none')
            ->expectsOutputToContain('Register App\\Canvas\\Elements\\GeneratedNoticeElement::class')
            ->assertSuccessful();

        require app_path('Canvas/Elements/GeneratedNoticeElement.php');
        $definitions = CanvasElements::list([GeneratedNoticeElement::class]);
        $element = CanvasElements::resolve($definitions, 'generated-notice');
        $this->assertSame('Generated Notice', $element->getLabel());
        $this->assertSame('canvas.elements.generated-notice', $element->view());
        $this->assertStringContainsString('x-text="data.caption"', ElementMarkup::template($element));
        ElementValidation::validate($element, ['caption' => 'A custom notice']);

        $this->expectException(ValidationException::class);
        ElementValidation::validate($element, ['caption' => '']);
    }

    public function test_nested_names_use_matching_namespaces_views_and_unique_types(): void
    {
        $this->artisan('make:canvas-element', ['--no-interaction' => true, 'name' => 'Marketing/promoCardElement'])->assertSuccessful();

        require app_path('Canvas/Elements/Marketing/PromoCardElement.php');
        $element = new PromoCardElement;
        $this->assertSame('marketing-promo-card', $element->type());
        $this->assertSame('Promo Card', $element->getLabel());
        $this->assertSame('canvas.elements.marketing.promo-card', $element->view());
        $this->assertFileExists(resource_path('views/canvas/elements/marketing/promo-card.blade.php'));
        $this->assertStringContainsString('x-text="data.caption"', ElementMarkup::template($element));
    }

    public function test_fully_qualified_names_use_the_application_namespace(): void
    {
        (new \ReflectionProperty($this->app, 'namespace'))->setValue($this->app, 'Company\\');
        $this->artisan('make:canvas-element', ['--no-interaction' => true, 'name' => 'Company\\Canvas\\Elements\\banner_element'])->assertSuccessful();

        require app_path('Canvas/Elements/BannerElement.php');
        $element = new BannerElement;
        $this->assertSame('banner', $element->type());
        $this->assertSame('canvas.elements.banner', $element->view());
    }

    public function test_a_class_collision_leaves_both_files_untouched(): void
    {
        $path = app_path('Canvas/Elements/NoticeElement.php');
        (new Filesystem)->ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'existing class');

        $this->artisan('make:canvas-element', ['--no-interaction' => true, 'name' => 'notice-element'])->assertFailed();

        $this->assertSame('existing class', file_get_contents($path));
        $this->assertFileDoesNotExist(resource_path('views/canvas/elements/notice.blade.php'));
    }

    public function test_a_view_collision_does_not_create_the_class(): void
    {
        $path = resource_path('views/canvas/elements/notice.blade.php');
        (new Filesystem)->ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'existing view');

        $this->artisan('make:canvas-element', ['--no-interaction' => true, 'name' => 'notice_element'])->assertFailed();

        $this->assertSame('existing view', file_get_contents($path));
        $this->assertFileDoesNotExist(app_path('Canvas/Elements/NoticeElement.php'));
    }

    public function test_force_replaces_both_generated_files(): void
    {
        $this->artisan('make:canvas-element', ['--no-interaction' => true, 'name' => 'NoticeElement'])->assertSuccessful();
        $class = app_path('Canvas/Elements/NoticeElement.php');
        $view = resource_path('views/canvas/elements/notice.blade.php');
        file_put_contents($class, 'existing class');
        file_put_contents($view, 'existing view');

        $this->artisan('make:canvas-element', ['--no-interaction' => true, 'name' => 'NoticeElement', '--force' => true])->assertSuccessful();

        $this->assertStringContainsString('class NoticeElement extends CanvasElement', file_get_contents($class));
        $this->assertStringContainsString('x-text="data.caption"', file_get_contents($view));
    }

    public function test_invalid_names_do_not_write_files(): void
    {
        foreach (['../Escape', 'Bad!Name', '123Notice', 'Notice/../Escape', 'Abstract', 'Marketing/class', 'String', 'int', 'Null', '_', 'CanvasElement', 'TextInput', 'Schema', 'App\\Other\\NoticeElement'] as $name) {
            $this->artisan('make:canvas-element', ['--no-interaction' => true, 'name' => $name])->assertFailed();
            $this->assertDirectoryDoesNotExist($this->directory);
        }
    }

    public function test_the_interactive_choice_can_extend_a_builtin_element(): void
    {
        $this->artisan('make:canvas-element', ['name' => 'InteractiveQuoteElement'])
            ->expectsQuestion('Which built-in element would you like to extend?', 'text')
            ->assertSuccessful();

        require app_path('Canvas/Elements/InteractiveQuoteElement.php');
        $element = new InteractiveQuoteElement;
        $this->assertInstanceOf(TextElement::class, $element);
        $this->assertStringContainsString('x-html="data.html"', ElementMarkup::template($element));
    }

    public function test_missing_name_is_requested_before_choosing_the_parent(): void
    {
        $this->artisan('make:canvas-element')
            ->expectsQuestion('What should the canvas element be named?', 'prompted quote element')
            ->expectsQuestion('Which built-in element would you like to extend?', 'text')
            ->assertSuccessful();

        require app_path('Canvas/Elements/PromptedQuoteElement.php');
        $element = new PromptedQuoteElement;
        $this->assertInstanceOf(TextElement::class, $element);
        $this->assertSame('prompted-quote', $element->type());
        $this->assertStringContainsString('x-html="data.html"', ElementMarkup::template($element));
    }

    public function test_all_builtin_parents_keep_their_native_forms_views_and_editor_behavior(): void
    {
        foreach ([
            'title' => [TitleElement::class, 'text'],
            'text' => [TextElement::class, 'text'],
            'image' => [ImageElement::class, 'image'],
            'drawing' => [DrawingElement::class, 'drawing'],
            'data-table' => [DataTableElement::class, 'table'],
        ] as $type => [$parentClass, $kind]) {
            $name = 'Inherited'.Str::studly($type).'Element';
            $this->artisan('make:canvas-element', ['name' => $name, '--extends' => $type])->assertSuccessful();

            require app_path('Canvas/Elements/'.$name.'.php');
            $class = 'App\\Canvas\\Elements\\'.$name;
            $element = new $class;
            $parent = new $parentClass;
            $this->assertInstanceOf($parentClass, $element);
            $this->assertSame('inherited-'.$type, $element->type());
            $this->assertSame($parent->getIcon(), $element->getIcon());
            $this->assertSame($parent->mediaCollections(), $element->mediaCollections());
            $this->assertTrue($element->showInToolbar());
            $this->assertSame($kind, CanvasElements::kind($element));
            $this->assertSame(ElementMarkup::template($parent), ElementMarkup::template($element));

            $properties = $this->defaultProperties($element);
            $this->assertSame($this->defaultProperties($parent), $properties);
            $this->assertArrayNotHasKey('caption', $properties);
            ElementValidation::validate($element, $element->normalize($properties));
            $this->assertStringNotContainsString('function form(', file_get_contents(app_path('Canvas/Elements/'.$name.'.php')));
        }
    }

    public function test_an_inherited_element_can_have_the_same_class_name_as_its_parent(): void
    {
        $this->artisan('make:canvas-element', ['name' => 'TextElement', '--extends' => 'text'])->assertSuccessful();

        require app_path('Canvas/Elements/TextElement.php');
        $element = new \App\Canvas\Elements\TextElement;
        $this->assertInstanceOf(TextElement::class, $element);
        $this->assertSame('text', $element->type());
        $this->assertStringContainsString('TextElement as BaseTextElement', file_get_contents(app_path('Canvas/Elements/TextElement.php')));
    }

    public function test_an_unknown_parent_does_not_write_files(): void
    {
        $this->artisan('make:canvas-element', ['name' => 'InvalidParentElement', '--extends' => 'unknown'])
            ->expectsOutputToContain('Choose one of: none, title, text, image, drawing, line, rectangle, ellipse, triangle, arrow, data-table.')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->directory);
    }

    private function defaultProperties(CanvasElement $element): array
    {
        $host = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $properties = [];
        };
        $schema = $element->form(Schema::make($host)->statePath('properties'));
        $schema->fill();

        return $schema->getState();
    }
}
