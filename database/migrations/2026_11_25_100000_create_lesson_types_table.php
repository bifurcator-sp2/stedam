<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник типов уроков.
 *
 * Ортогонален домену курсов: курсы используют его только для порога допуска
 * к следующему уроку (course.next_lesson_threshold = max(course, type)),
 * UI/фронт — для визуальной интерпретации (домашка, контрольная, ...).
 *
 * code — стабильный машинный идентификатор ('home_work'), не меняется.
 * next_lesson_threshold — доля 0..1: какую часть предыдущего урока нужно
 * набрать, чтобы открылся следующий.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_types', function (Blueprint $table) {
            $table->id();

            $table->string('code', 32)->unique();

            // CHECK BETWEEN 0 AND 1 ставится в общем CHECK-е ниже.
            $table->float('next_lesson_threshold');

            // UI-подсказка (необязательно).
            $table->string('color', 16)->nullable();

            // Порядок в списках селекторов.
            $table->integer('sort')->default(0);

            $table->timestampsTz();
        });

        // Домен: порог в (0..1], не ноль и не больше единицы.
        DB::statement(
            'ALTER TABLE lesson_types '
            . 'ADD CONSTRAINT lesson_types_threshold_check '
            . 'CHECK (next_lesson_threshold > 0 AND next_lesson_threshold <= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_types');
    }
};
