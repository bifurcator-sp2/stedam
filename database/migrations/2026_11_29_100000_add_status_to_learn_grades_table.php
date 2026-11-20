<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Статус обработки оценки Прохождения.
 *
 * Жизненный цикл: pending → in_progress → pre_graded → graded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learn_grades', function (Blueprint $table) {
            $table->enum('status', ['pending', 'in_progress', 'pre_graded', 'graded', 'failed'])
                ->default('pending');

            $table->index('status', 'learn_grades_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('learn_grades', function (Blueprint $table) {
            $table->dropIndex('learn_grades_status_idx');
            $table->dropColumn('status');
        });
    }
};
