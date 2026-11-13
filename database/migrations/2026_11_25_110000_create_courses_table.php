<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Курсы обучения.
 *
 * user_id = NULL — системный курс (доступен всем, кроме parent).
 * tax_id — узел таксономии, к которому привязан курс (одна ветка).
 * complexity — производная: среднее сложностей всех уроков курса.
 * next_lesson_threshold — какой долю (0..1] предыдущего урока нужно набрать,
 *                         чтобы открылся следующий.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();

            // NULL = системный курс.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('title', 255);
            $table->text('description')->default('');

            // Доля 0..1 (CHECK ниже).
            $table->float('next_lesson_threshold')->default(1.0);

            $table->string('locale', 8);

            // Привязка к ветке таксономии. NULL — не привязан.
            $table->foreignId('tax_id')
                ->nullable()
                ->constrained('taxonomies')
                ->nullOnDelete();

            // Производная: среднее сложностей уроков.
            $table->float('complexity')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_id', 'courses_user_id_idx');
            $table->index('tax_id', 'courses_tax_id_idx');
            $table->index('deleted_at', 'courses_deleted_at_idx');
        });

        DB::statement(
            'CREATE INDEX courses_updated_at_desc_idx ON courses (updated_at DESC)'
        );

        DB::statement(
            'ALTER TABLE courses '
            . 'ADD CONSTRAINT courses_threshold_check '
            . 'CHECK (next_lesson_threshold > 0 AND next_lesson_threshold <= 1)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS courses_updated_at_desc_idx');
        Schema::dropIfExists('courses');
    }
};
