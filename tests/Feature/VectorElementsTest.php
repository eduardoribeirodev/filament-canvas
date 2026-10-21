<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Elements\DrawingElement;
use EduardoRibeiroDev\FilamentCanvas\Support\BlockValidator;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class VectorElementsTest extends TestCase
{
    public function test_vector_elements_have_native_defaults_and_shared_svg_templates(): void
    {
        $registry = ElementRegistry::defaults();
        foreach (['line', 'drawing', 'rectangle', 'ellipse', 'triangle', 'arrow'] as $type) {
            $host = new class extends Component implements HasSchemas
            {
                use InteractsWithSchemas;

                public array $properties = [];
            };
            $element = $registry->get($type);
            $schema = $element->form(Schema::make($host)->statePath('properties'));
            $schema->fill();
            $properties = $schema->getState();
            app(BlockValidator::class)->validate([$this->block($type, $properties)], new CanvasDocument([], 500, 400), $registry);
            $this->assertStringContainsString('<svg', view($element->view())->render());
            $this->assertSame('#E60014', $properties['drawing']['stroke']);
        }
        $this->assertSame('line', $registry->metadataForEditor()['line']['kind']);
        $this->assertSame('drawing', $registry->metadataForEditor()['drawing']['kind']);
        $this->assertSame('shape', $registry->metadataForEditor()['rectangle']['kind']);
    }

    public function test_point_coordinates_and_closed_drawings_are_validated_by_the_native_form(): void
    {
        $properties = ['drawing' => ['closed' => true, 'points' => [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 100]]]];
        foreach ([$properties, ['drawing' => ['points' => [['x' => -1, 'y' => 0], ['x' => 100, 'y' => 100]]]]] as $invalid) {
            try {
                app(BlockValidator::class)->validate([$this->block('drawing', $invalid)], new CanvasDocument([], 500, 400), ElementRegistry::from([new DrawingElement]));
                $this->fail('Invalid point geometry must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    private function block(string $type, array $properties): array
    {
        return ['type' => $type, 'data' => ['name' => 'Vector', 'x_pt' => 10, 'y_pt' => 20, 'width_pt' => 200, 'height_pt' => 100,
            'rotation_deg' => 0, 'visible' => true, 'properties' => $properties]];
    }
}
