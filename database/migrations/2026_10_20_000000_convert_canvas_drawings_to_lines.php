<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('canvas_drawing_line_migrations')) {
            Schema::create('canvas_drawing_line_migrations', function (Blueprint $table): void {
                $table->unsignedBigInteger('element_id')->primary();
            });
        }

        DB::transaction(function (): void {
            DB::table('canvas_elements')->where('type', 'drawing')->orderBy('id')->chunkById(500, function ($elements): void {
                foreach ($elements as $element) {
                    $drawing = json_decode($element->properties ?? '{}', true)['drawing'] ?? [];
                    if (isset($drawing['points']) || ! isset($drawing['x1'], $drawing['y1'], $drawing['x2'], $drawing['y2'])) {
                        continue;
                    }

                    DB::table('canvas_drawing_line_migrations')->insertOrIgnore(['element_id' => $element->id]);
                    DB::table('canvas_elements')->where('id', $element->id)->update(['type' => 'line']);
                }
            });
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('canvas_drawing_line_migrations')) {
            return;
        }

        DB::table('canvas_elements')->where('type', 'line')
            ->whereIn('id', DB::table('canvas_drawing_line_migrations')->select('element_id'))
            ->update(['type' => 'drawing']);

        Schema::dropIfExists('canvas_drawing_line_migrations');
    }
};
