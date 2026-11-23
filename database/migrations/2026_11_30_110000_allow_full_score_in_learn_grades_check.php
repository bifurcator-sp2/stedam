<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Разрешаем оценку ровно 1.0: CHECK (0 < x <= 1) вместо (0 < x < 1)
 * для pre_grade, pre_grade_confidence и grade.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE learn_grades DROP CONSTRAINT IF EXISTS learn_grades_pre_grade_check');
        DB::statement(
            'ALTER TABLE learn_grades '
            . 'ADD CONSTRAINT learn_grades_pre_grade_check '
            . 'CHECK (pre_grade IS NULL OR (pre_grade > 0 AND pre_grade <= 1))'
        );

        DB::statement('ALTER TABLE learn_grades DROP CONSTRAINT IF EXISTS learn_grades_pre_grade_confidence_check');
        DB::statement(
            'ALTER TABLE learn_grades '
            . 'ADD CONSTRAINT learn_grades_pre_grade_confidence_check '
            . 'CHECK (pre_grade_confidence IS NULL OR (pre_grade_confidence > 0 AND pre_grade_confidence <= 1))'
        );

        DB::statement('ALTER TABLE learn_grades DROP CONSTRAINT IF EXISTS learn_grades_grade_check');
        DB::statement(
            'ALTER TABLE learn_grades '
            . 'ADD CONSTRAINT learn_grades_grade_check '
            . 'CHECK (grade IS NULL OR (grade > 0 AND grade <= 1))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE learn_grades DROP CONSTRAINT IF EXISTS learn_grades_pre_grade_check');
        DB::statement(
            'ALTER TABLE learn_grades '
            . 'ADD CONSTRAINT learn_grades_pre_grade_check '
            . 'CHECK (pre_grade IS NULL OR (pre_grade > 0 AND pre_grade < 1))'
        );

        DB::statement('ALTER TABLE learn_grades DROP CONSTRAINT IF EXISTS learn_grades_pre_grade_confidence_check');
        DB::statement(
            'ALTER TABLE learn_grades '
            . 'ADD CONSTRAINT learn_grades_pre_grade_confidence_check '
            . 'CHECK (pre_grade_confidence IS NULL OR (pre_grade_confidence > 0 AND pre_grade_confidence < 1))'
        );

        DB::statement('ALTER TABLE learn_grades DROP CONSTRAINT IF EXISTS learn_grades_grade_check');
        DB::statement(
            'ALTER TABLE learn_grades '
            . 'ADD CONSTRAINT learn_grades_grade_check '
            . 'CHECK (grade IS NULL OR (grade > 0 AND grade < 1))'
        );
    }
};
