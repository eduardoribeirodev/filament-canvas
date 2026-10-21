<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\Data\CanvasDocument;
use EduardoRibeiroDev\FilamentCanvas\Elements\DrawingElement;
use EduardoRibeiroDev\FilamentCanvas\Support\CanvasElements;
use EduardoRibeiroDev\FilamentCanvas\Support\BlockValidator;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class VectorElementsTest extends TestCase
{
    public function test_vector_elements_have_native_defaults_and_shared_svg_templates(): void
    {
        $definitions = CanvasElements::defaults();
        foreach (['line', 'drawing', 'rectangle', 'ellipse', 'triangle', 'arrow'] as $type) {
            $host = new class extends Component implements HasSchemas
            {
                use InteractsWithSchemas;

                public array $properties = [];
            };
            $element = CanvasElements::resolve($definitions, $type);
            $schema = $element->form(Schema::make($host)->statePath('properties'));
            $schema->fill();
            $properties = $schema->getState();
            app(BlockValidator::class)->validate([$this->block($type, $properties)], new CanvasDocument([], 500, 400), $definitions);
            $this->assertStringContainsString('<svg', view($element->view())->render());
            $this->assertSame('#E60014', $properties['drawing']['stroke']);
        }
        $this->assertSame('line', CanvasElements::metadataForEditor($definitions)['line']['kind']);
        $this->assertSame('drawing', CanvasElements::metadataForEditor($definitions)['drawing']['kind']);
        $this->assertSame('shape', CanvasElements::metadataForEditor($definitions)['rectangle']['kind']);
    }

    public function test_point_coordinates_and_closed_drawings_are_validated_by_the_native_form(): void
    {
        $properties = ['drawing' => ['closed' => true, 'points' => [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 100]]]];
        foreach ([$properties, ['drawing' => ['points' => [['x' => -1, 'y' => 0], ['x' => 100, 'y' => 100]]]]] as $invalid) {
            try {
                app(BlockValidator::class)->validate([$this->block('drawing', $invalid)], new CanvasDocument([], 500, 400), CanvasElements::list([new DrawingElement]));
                $this->fail('Invalid point geometry must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_migration_changes_only_old_drawing_types_and_rolls_back_only_the_converted_records(): void
    {
        (require dirname(__DIR__, 2).'/database/migrations/2026_10_10_020000_create_canvas_elements_table.php')->up();
        $properties = json_encode(['drawing' => ['x1' => 10, 'y1' => 80, 'x2' => 90, 'y2' => 5, 'stroke_width' => 2]]);
        $base = ['elementable_type' => 'Document', 'elementable_id' => 1, 'name' => 'Original', 'x_pt' => 10, 'y_pt' => 20, 'width_pt' => 200, 'height_pt' => 100, 'rotation_deg' => 25, 'visible' => false, 'layer' => 4];
        DB::table('canvas_elements')->insert([
            [...$base, 'id' => 1, 'type' => 'drawing', 'properties' => $properties],
            [...$base, 'id' => 2, 'type' => 'line', 'properties' => $properties],
            [...$base, 'id' => 3, 'type' => 'drawing', 'properties' => json_encode(['drawing' => ['points' => [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 100]]]])],
        ]);
        $original = DB::table('canvas_elements')->where('id', 1)->first();
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_20_000000_convert_canvas_drawings_to_lines.php';
        $migration->up();
        $converted = DB::table('canvas_elements')->where('id', 1)->first();
        $this->assertSame('line', $converted->type);
        unset($original->type, $converted->type);
        $this->assertEquals($original, $converted);
        $migration->up();
        $this->assertSame('drawing', DB::table('canvas_elements')->where('id', 3)->value('type'));
        $this->assertSame(1, DB::table('canvas_drawing_line_migrations')->count());
        $migration->down();
        $this->assertSame(['drawing', 'line', 'drawing'], DB::table('canvas_elements')->orderBy('id')->pluck('type')->all());
    }

    private function block(string $type, array $properties): array
    {
        return ['type' => $type, 'data' => ['name' => 'Vector', 'x_pt' => 10, 'y_pt' => 20, 'width_pt' => 200, 'height_pt' => 100,
            'rotation_deg' => 0, 'visible' => true, 'properties' => $properties]];
    }
}
