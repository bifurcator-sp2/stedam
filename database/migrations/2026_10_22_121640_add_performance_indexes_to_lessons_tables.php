<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Основная таблица уроков (оптимизация выдачи и поиска)
        Schema::table('lessons', function (Blueprint $table) {
            // Составной индекс для WHERE user_id = ? ORDER BY id DESC
            // Это уберет filesort при пагинации
            $table->index(['user_id', 'id'], 'idx_lessons_user_id_id_desc');

            // Полнотекстовый индекс для быстрого поиска по названию и описанию
            // Ищет по словам, а не по подстроке (гораздо быстрее LIKE %%)
            $table->fullText(['title', 'description'], 'idx_lessons_fulltext');
        });

        // 2. Таблица связи (оптимизация фильтрации по таксономии)
        Schema::table('lesson_taxonomy', function (Blueprint $table) {
            // Для whereHas('taxonomies')
            $table->index(['taxonomy_id', 'lesson_id'], 'idx_lesson_taxonomy_tax_lesson');

            // Для жадной загрузки with('taxonomies')
            $table->index(['lesson_id', 'taxonomy_id'], 'idx_lesson_taxonomy_lesson_tax');
        });

        // 3. Таблица блоков (оптимизация подсчета withCount)
        Schema::table('lesson_blocks', function (Blueprint $table) {
            // Для быстрого COUNT(*) WHERE lesson_id = ?
            $table->index('lesson_id', 'idx_lesson_blocks_lesson_id');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex('idx_lessons_user_id_id_desc');
            $table->dropFullText('idx_lessons_fulltext');
        });

        Schema::table('lesson_taxonomy', function (Blueprint $table) {
            $table->dropIndex('idx_lesson_taxonomy_tax_lesson');
            $table->dropIndex('idx_lesson_taxonomy_lesson_tax');
        });

        Schema::table('lesson_blocks', function (Blueprint $table) {
            $table->dropIndex('idx_lesson_blocks_lesson_id');
        });
    }
};
