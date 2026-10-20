<?php

namespace EduardoRibeiroDev\FilamentCanvas\Console\Commands;

use EduardoRibeiroDev\FilamentCanvas\Elements\ArrowElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DataTableElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\DrawingElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\EllipseElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\ImageElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\LineElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\RectangleElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TitleElement;
use EduardoRibeiroDev\FilamentCanvas\Elements\TriangleElement;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

class MakeCanvasElementCommand extends GeneratorCommand
{
    protected $signature = 'make:canvas-element
        {name : The element class name, relative to App\\Canvas\\Elements}
        {--extends= : Built-in element to inherit: none, title, text, image, drawing, line, rectangle, ellipse, triangle, arrow, data-table}
        {--force : Overwrite the element class and view if they already exist}';

    protected $description = 'Create a Canvas element with a Filament form and an Alpine Blade view';

    protected $type = 'Canvas element';

    public function handle(): int
    {
        $name = $this->qualifyClass($this->getNameInput());
        $name = Str::beforeLast($name, '\\').'\\'.Str::studly(class_basename($name));

        foreach (explode('\\', $name) as $segment) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment)
                || $this->isReservedName($segment)
                || in_array(strtolower($segment), ['bool', 'float', 'int', 'string', 'object', 'mixed', 'never', 'void', 'iterable', 'null'], true)) {
                $this->components->error('Use a valid PHP class name, such as NoticeElement or Marketing/NoticeElement.');

                return self::FAILURE;
            }
        }

        $namespace = $this->getDefaultNamespace(trim($this->rootNamespace(), '\\')).'\\';
        if (! str_starts_with($name, $namespace)) {
            $this->components->error("The element must belong to the {$namespace} namespace.");

            return self::FAILURE;
        }

        $segments = explode('\\', Str::after($name, $namespace));
        $label = Str::headline(preg_replace('/Element$/', '', array_pop($segments)) ?: class_basename($name));
        $segments[] = $label;
        $segments = array_map(fn (string $segment): string => Str::slug(Str::headline($segment)), $segments);
        if (in_array('', $segments, true)) {
            $this->components->error('Each namespace and class segment must contain a letter or number.');

            return self::FAILURE;
        }
        $view = 'canvas.elements.'.implode('.', $segments);
        $classPath = $this->getPath($name);
        $viewPath = resource_path('views/'.str_replace('.', '/', $view).'.blade.php');

        foreach ([$classPath, $viewPath] as $path) {
            if ($this->files->exists($path) && ! $this->option('force')) {
                $this->components->error("File [{$path}] already exists. Use --force to overwrite the class and view.");

                return self::FAILURE;
            }
        }

        $parents = [
            'none' => CanvasElement::class,
            'title' => TitleElement::class,
            'text' => TextElement::class,
            'image' => ImageElement::class,
            'drawing' => DrawingElement::class,
            'line' => LineElement::class,
            'rectangle' => RectangleElement::class,
            'ellipse' => EllipseElement::class,
            'triangle' => TriangleElement::class,
            'arrow' => ArrowElement::class,

            'data-table' => DataTableElement::class,
        ];
        $base = $this->option('extends') ?? ($this->input->isInteractive() ? select(
            label: 'Which built-in element would you like to extend?',
            options: ['none' => 'None (generic element)', 'title' => 'Title', 'text' => 'Text', 'image' => 'Image', 'drawing' => 'Drawing', 'line' => 'Line', 'rectangle' => 'Rectangle', 'ellipse' => 'Ellipse', 'triangle' => 'Triangle', 'arrow' => 'Arrow', 'data-table' => 'Data table'],
            default: 'none',
            scroll: 6,
        ) : 'none');
        if (! isset($parents[$base])) {
            $this->components->error('Choose one of: '.implode(', ', array_keys($parents)).'.');

            return self::FAILURE;
        }
        if ($base === 'none' && in_array(strtolower(class_basename($name)), ['canvaselement', 'textinput', 'schema'], true)) {
            $this->components->error('The class name conflicts with an import in the element template. Choose a name such as NoticeElement.');

            return self::FAILURE;
        }
        $this->input->setOption('extends', $base);
        $parentClass = $parents[$base];
        $parent = class_basename($parentClass);
        $parentAlias = strcasecmp(class_basename($name), $parent) === 0 ? 'Base'.$parent : $parent;

        $class = strtr($this->buildClass($name), [
            '{{ type }}' => implode('-', $segments),
            '{{ label }}' => $label,
            '{{ view }}' => $view,
            '{{ caption }}' => 'New '.Str::lower($label),
            '{{ parent_import }}' => $parent.($parentAlias === $parent ? '' : ' as '.$parentAlias),
            '{{ parent_class }}' => $parentAlias,
        ]);
        $template = $base === 'none'
            ? $this->files->get(__DIR__.'/../../../stubs/element-view.stub')
            : "@include('".(new $parentClass)->view()."')\n";

        $this->files->put($this->makeDirectory($classPath), $this->sortImports($class));
        $this->files->put($this->makeDirectory($viewPath), $template);

        $this->components->info("Canvas element [{$classPath}] created successfully.");
        $this->components->info("Canvas view [{$viewPath}] created successfully.");
        $this->line("Register {$name}::class in filament-canvas.elements or your editor's extraElements([...]).");

        return self::SUCCESS;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Canvas\\Elements';
    }

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/'.($this->option('extends') === 'none' ? 'element.stub' : 'element-inherited.stub');
    }
}
