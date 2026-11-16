<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Множитель базового порога допуска к следующему уроку.
 *
 * Базовый порог специфичен для типа урока (lesson_types.next_lesson_threshold),
 * курс лишь масштабирует его: threshold = base * threshold_multipler.
 * Дефолт 1.0 — «не менять требование типа».
 *
 * Обязательность ввода обеспечивает API курсов: при создании курса
 * threshold_multipler обязателен, при PATCH — необязателен («не менять»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->float('threshold_multipler')->default(1.0);
        });

        DB::statement(
            'ALTER TABLE courses '
            . 'ALTER COLUMN threshold_multipler SET NOT NULL'
        );

        DB::statement(
            'ALTER TABLE courses '
            . 'ADD CONSTRAINT courses_threshold_multipler_check '
            . 'CHECK (threshold_multipler > 0)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE courses '
            . 'DROP CONSTRAINT IF EXISTS courses_threshold_multipler_check'
        );

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('threshold_multipler');
        });
    }
};
