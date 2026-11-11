<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Каскадные удаления вокруг lessons — для удобной ручной админки БД
 * (не для продакшена).
 *
 * Проблема: Lesson помечен SoftDeletes. В коде урок «удаляется» через
 * UPDATE deleted_at, и это никого не трогает. Но прямая чистка в БД
 * (DELETE FROM lessons / forceDelete) упирается в RESTRICT-констрейнты
 * на lesson_blocks.lesson_id и learn_statuses.lesson_id — PostgreSQL
 * не даёт удалить урок, пока живы зависящие строки.
 *
 * Плюс отдельный случай: learn_statuses.learn_id → learns стоял RESTRICT
 * («learn не удаляется, только архивируется»). При каскадном удалении
 * learns через lesson_id порядок срабатывания каскадов не гарантирован,
 * и этот RESTRICT блокировал всю цепочку. Поэтому тоже CASCADE.
 *
 * Решение: заменить RESTRICT на CASCADE там, где зависимые строки
 * без урока бессмысленны:
 *
 *   lessons → lesson_blocks            CASCADE
 *   lessons → learn_statuses           CASCADE
 *   learns  → learn_statuses           CASCADE
 *
 * Остальные ссылки на lessons уже каскадные:
 *   lessons → lesson_lesson_block_as_test  CASCADE
 *   lessons → learns                       CASCADE
 *   lessons → lesson_taxonomy              CASCADE
 *
 * Поэтому после миграции `DELETE FROM lessons WHERE id = ?` сносит весь
 * «куст» урока одним запросом. SoftDeletes в коде продолжает работать
 * как раньше и миграции не мешает — каскад срабатывает только на
 * физическом DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_blocks', function (Blueprint $table) {
            $table->dropForeign(['lesson_id']);
            $table->foreign('lesson_id')
                ->references('id')
                ->on('lessons')
                ->cascadeOnDelete();
        });

        Schema::table('learn_statuses', function (Blueprint $table) {
            $table->dropForeign(['lesson_id']);
            $table->foreign('lesson_id')
                ->references('id')
                ->on('lessons')
                ->cascadeOnDelete();
        });

        Schema::table('learn_statuses', function (Blueprint $table) {
            $table->dropForeign(['learn_id']);
            $table->foreign('learn_id')
                ->references('id')
                ->on('learns')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Возвращаем RESTRICT — поведение по умолчанию из исходной схемы.
        Schema::table('lesson_blocks', function (Blueprint $table) {
            $table->dropForeign(['lesson_id']);
            $table->foreign('lesson_id')
                ->references('id')
                ->on('lessons')
                ->restrictOnDelete();
        });

        Schema::table('learn_statuses', function (Blueprint $table) {
            $table->dropForeign(['lesson_id']);
            $table->foreign('lesson_id')
                ->references('id')
                ->on('lessons')
                ->restrictOnDelete();
        });

        Schema::table('learn_statuses', function (Blueprint $table) {
            $table->dropForeign(['learn_id']);
            $table->foreign('learn_id')
                ->references('id')
                ->on('learns')
                ->restrictOnDelete();
        });
    }
};
