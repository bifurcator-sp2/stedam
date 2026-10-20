<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('block_block_index', function (Blueprint $table) {
            $table->id();

            $table->foreignId('block_index_id')
                ->constrained('block_indexes')
                ->cascadeOnDelete();

            $table->foreignId('block_id')
                ->constrained('blocks')
                ->cascadeOnDelete();

            // Позиция блока среди соседей внутри конкретного узла дерева.
            $table->unsignedInteger('order')->default(0);

            $table->timestamps();

            // Один блок — максимум один раз в узле
            $table->unique(['block_index_id', 'block_id']);

            // Быстрая выборка «блоки узла в порядке»
            $table->index(['block_index_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_block_index');
    }
};
