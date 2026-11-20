<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Оценка Прохождения урока.
 *
 * pre_grade / pre_grade_confidence — предварительная (автоматическая) оценка
 * и её уверенность; grade — итоговая оценка от преподавателя.
 * Все три величины — доля в (0..1) или NULL.
 *
 * grader_user_id — преподаватель, выставивший итоговую оценку.
 * grader_comment — комментарий преподавателя к оценке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learn_grades', function (Blueprint $table) {
            $table->id();

            // Прохождение, к которому относится оценка.
            $table->foreignId('learn_id')
                ->constrained('learns')
                ->cascadeOnDelete();

            // Доля (0..1) (CHECK ниже).
            $table->float('pre_grade')->nullable();
            $table->float('pre_grade_confidence')->nullable();
            $table->float('grade')->nullable();

            $table->foreignId('grader_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('grader_comment')->default('');

            $table->timestampsTz();

            $table->index('learn_id', 'learn_grades_learn_id_idx');
            $table->index('grader_user_id', 'learn_grades_grader_user_id_idx');
        });

        DB::statement(
            'ALTER TABLE learn_grades '
            . 'ADD CONSTRAINT learn_grades_pre_grade_check '
            . 'CHECK (pre_grade IS NULL OR (pre_grade > 0 AND pre_grade < 1))'
        );

        DB::statement(
            'ALTER TABLE learn_grades '
            . 'ADD CONSTRAINT learn_grades_pre_grade_confidence_check '
            . 'CHECK (pre_grade_confidence IS NULL OR (pre_grade_confidence > 0 AND pre_grade_confidence < 1))'
        );

        DB::statement(
            'ALTER TABLE learn_grades '
            . 'ADD CONSTRAINT learn_grades_grade_check '
            . 'CHECK (grade IS NULL OR (grade > 0 AND grade < 1))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('learn_grades');
    }
};
