<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Локализации названий типов уроков.
 *
 * По образцу taxonomy_translations / role_translations: одна строка на пару
 * (lesson_type_id, locale). Аксессор LessonType::$title делает fallback на
 * config('app.fallback_locale') и на первую доступную локаль.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_type_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lesson_type_id')
                ->constrained('lesson_types')
                ->cascadeOnDelete();

            $table->string('locale', 8);
            $table->string('title');
            $table->text('description')->nullable();

            $table->timestampsTz();

            $table->unique(
                ['lesson_type_id', 'locale'],
                'lesson_type_translations_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_type_translations');
    }
};
