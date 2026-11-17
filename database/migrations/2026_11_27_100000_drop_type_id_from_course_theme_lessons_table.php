<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тип урока в связке "тема → урок" не хранится: он уже есть у самого урока
 * (lessons.type — код из lesson_types), поэтому колонка была избыточной.
 *
 * time_gap теперь вычисляется при сохранении курса как
 * lesson_types.time_gap × courses.time_gap_multipler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_theme_lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('type_id');
        });
    }

    public function down(): void
    {
        Schema::table('course_theme_lessons', function (Blueprint $table) {
            $table->foreignId('type_id')
                ->nullable()
                ->after('lesson_id')
                ->constrained('lesson_types')
                ->nullOnDelete();
        });
    }
};
