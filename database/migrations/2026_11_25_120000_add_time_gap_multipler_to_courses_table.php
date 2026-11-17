<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Множитель паузы (time_gap) между уроками курса.
 *
 * Курс масштабирует time_gap, заданный на уроке. Дефолт 1.0 — «не менять
 * паузу урока». Допустимый диапазон — [0.0, 100.0]: 0 означает «без паузы».
 *
 * Обязательность ввода обеспечивает API курсов: при создании курса
 * time_gap_multipler обязателен, при PATCH — необязателен («не менять»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->float('time_gap_multipler')->default(1.0);
        });

        DB::statement(
            'ALTER TABLE courses '
            . 'ALTER COLUMN time_gap_multipler SET NOT NULL'
        );

        DB::statement(
            'ALTER TABLE courses '
            . 'ADD CONSTRAINT courses_time_gap_multipler_check '
            . 'CHECK (time_gap_multipler >= 0 AND time_gap_multipler <= 100)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE courses '
            . 'DROP CONSTRAINT IF EXISTS courses_time_gap_multipler_check'
        );

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('time_gap_multipler');
        });
    }
};
