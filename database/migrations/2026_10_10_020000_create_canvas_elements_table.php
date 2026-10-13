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
            $table->string('nome');
            $table->string('tipo');
            foreach (['x_pt', 'y_pt', 'largura_pt', 'altura_pt'] as $field) {
                $table->decimal($field, 10, 4);
            }
            $table->decimal('rotacao_graus', 7, 3)->default(0);
            $table->integer('camada')->default(0);
            $table->boolean('visivel')->default(true);
            $table->json('dados')->nullable();
            $table->timestamps();
            $table->index(['elementable_type', 'elementable_id', 'camada'], 'canvas_elements_owner_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canvas_elements');
    }
};
