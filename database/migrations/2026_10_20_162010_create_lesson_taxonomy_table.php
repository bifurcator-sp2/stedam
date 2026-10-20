<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_lesson_taxonomy_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_taxonomy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('taxonomy_id')->constrained('taxonomies')->cascadeOnDelete();
            $table->timestamps();

            // Урок может быть только в одном узле (на данном этапе)
            $table->unique('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_taxonomy');
    }
};
