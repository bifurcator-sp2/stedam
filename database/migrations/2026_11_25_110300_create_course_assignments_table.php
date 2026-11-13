<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Назначение курса ученику.
 *
 * Пара (course_id, student_id) уникальна — повторное назначение недопустимо
 * (доменное исключение CourseAlreadyAssigned).
 *
 * completion и grade — производные: пересчитываются синхронно стратегиями
 * при событии "урок оценён". Не редактируются вручную.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnDelete();

            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('assigned_by')
                ->constrained('users')
                ->restrictOnDelete();

            // Производные величины в [0..1] (CHECK ниже).
            $table->float('completion')->default(0);
            $table->float('grade')->default(0);

            $table->timestampsTz();

            $table->unique(['course_id', 'student_id'], 'course_assignments_course_student_unique');
            $table->index('student_id', 'course_assignments_student_id_idx');
            $table->index('course_id', 'course_assignments_course_id_idx');
        });

        DB::statement(
            'ALTER TABLE course_assignments '
            . 'ADD CONSTRAINT course_assignments_completion_check '
            . 'CHECK (completion >= 0 AND completion <= 1)'
        );

        DB::statement(
            'ALTER TABLE course_assignments '
            . 'ADD CONSTRAINT course_assignments_grade_check '
            . 'CHECK (grade >= 0 AND grade <= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('course_assignments');
    }
};
