<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аренда (lease) на обработку LearnGrade.
 *
 * Позволяет запускать несколько воркеров одновременно: клейм делается
 * короткой транзакцией по статусу, а владение конкретной строкой
 * подтверждается полями аренды.
 *
 * pre_grade_worker      — идентификатор воркера, взявшего строку.
 * pre_grade_lease_until — до какого момента аренда считается живой;
 *                         истёкшие pre_grading можно вернуть в pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learn_grades', function (Blueprint $table) {
            $table->string('pre_grade_worker', 64)->nullable();
            $table->timestampTz('pre_grade_lease_until')->nullable();

            $table->index(
                ['status', 'pre_grade_lease_until'],
                'learn_grades_status_lease_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('learn_grades', function (Blueprint $table) {
            $table->dropIndex('learn_grades_status_lease_idx');
            $table->dropColumn(['pre_grade_worker', 'pre_grade_lease_until']);
        });
    }
};
