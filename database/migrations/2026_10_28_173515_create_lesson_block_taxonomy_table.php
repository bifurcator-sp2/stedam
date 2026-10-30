<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_lesson_block_taxonomy_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_block_taxonomy', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lesson_block_id')
                ->constrained('lesson_blocks')
                ->cascadeOnDelete();

            $table->foreignId('taxonomy_id')
                ->constrained('taxonomies')
                ->cascadeOnDelete();

            $table->timestampsTz();

            // Защита от дублей
            $table->unique(['lesson_block_id', 'taxonomy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_block_taxonomy');
    }
};
