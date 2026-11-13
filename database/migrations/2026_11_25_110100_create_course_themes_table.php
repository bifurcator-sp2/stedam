<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Темы курса — упорядоченные контейнеры уроков.
 *
 * "order" уникален внутри курса (инвариант домена: плоский порядок тем
 * нормализуется в 1..N без дыр).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_themes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnDelete();

            $table->string('title', 255);
            $table->integer('order');

            $table->timestampsTz();

            $table->unique(['course_id', 'order'], 'course_themes_course_order_unique');
            $table->index('course_id', 'course_themes_course_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_themes');
    }
};
