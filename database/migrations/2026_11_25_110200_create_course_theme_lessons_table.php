<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Связь "тема курса → урок" с параметрами планирования.
 *
 * type_id — тип урока (справочник lesson_types), влияет на порог допуска:
 *   threshold = max(courses.next_lesson_threshold, lesson_types.next_lesson_threshold)
 *
 * time_gap — разрыв в ЧАСАХ от плановой даты предыдущего урока до этого.
 *   Применяется к планируемому уроку L_next: schedule_date(L_next) =
 *   schedule_date(L_prev) + interval '<time_gap> hour'.
 *
 * ON DELETE CASCADE у lesson_id: урок удалён в Lessons → все его связи в курсах
 * снимаются. Висящие строки на мёртвый урок ломают листинг "в каких курсах урок"
 * и лендинг курса (урок без контента).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_theme_lessons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('theme_id')
                ->constrained('course_themes')
                ->cascadeOnDelete();

            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete();

            $table->foreignId('type_id')
                ->nullable()
                ->constrained('lesson_types')
                ->nullOnDelete();

            $table->integer('order');

            // Часы (целое). Дефолт — config('course.lessons.time_gap', 2).
            $table->integer('time_gap')->default(2);

            $table->timestampsTz();

            $table->unique(['theme_id', 'order'], 'course_theme_lessons_theme_order_unique');
            $table->unique(['theme_id', 'lesson_id'], 'course_theme_lessons_theme_lesson_unique');
            $table->index('lesson_id', 'course_theme_lessons_lesson_id_idx');
        });

        DB::statement(
            'ALTER TABLE course_theme_lessons '
            . 'ADD CONSTRAINT course_theme_lessons_time_gap_check '
            . 'CHECK (time_gap >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('course_theme_lessons');
    }
};
