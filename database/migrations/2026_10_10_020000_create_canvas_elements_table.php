<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canvas_elements', function (Blueprint $table): void {
            $table->id();
            $table->morphs('elementable');
            $table->string('name');
            $table->string('type');
            foreach (['x_pt', 'y_pt', 'width_pt', 'height_pt'] as $field) {
                $table->decimal($field, 10, 4);
            }
            $table->decimal('rotation_deg', 7, 3)->default(0);
            $table->integer('layer')->default(0);
            $table->boolean('visible')->default(true);
            $table->json('properties')->nullable();
            $table->timestamps();
            $table->index(['elementable_type', 'elementable_id', 'layer'], 'canvas_elements_owner_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canvas_elements');
    }
};
