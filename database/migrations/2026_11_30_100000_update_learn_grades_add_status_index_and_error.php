<?php

use App\Enums\LearnGradeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Воркер предварительной оценки.
 *
 * - Расширяет CHECK на status: добавляет pre_grading (атомарный claim воркера).
 * - error — текст последней ошибки для упавших оценок (диагностика).
 * - Составной индекс (status, created_at) под выборку «самый старый pending».
 */
return new class extends Migration
{
    public function up(): void
    {
        // Пересоздаём CHECK с актуальным набором значений из LearnGradeStatus.
        DB::statement('ALTER TABLE learn_grades DROP CONSTRAINT IF EXISTS learn_grades_status_check');
        DB::statement(
            'ALTER TABLE learn_grades ADD CONSTRAINT learn_grades_status_check '
            . 'CHECK (status IN (' . $this->quotedStatuses() . '))'
        );

        Schema::table('learn_grades', function (Blueprint $table) {
            $table->text('error')->nullable()->after('grader_comment');
            $table->index(['status', 'created_at'], 'learn_grades_status_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('learn_grades', function (Blueprint $table) {
            $table->dropIndex('learn_grades_status_created_at_idx');
            $table->dropColumn('error');
        });

        // Возврат к исходному набору статусов (без pre_grading).
        DB::table('learn_grades')
            ->where('status', LearnGradeStatus::PreGrading->value)
            ->update(['status' => LearnGradeStatus::Pending->value]);

        DB::statement('ALTER TABLE learn_grades DROP CONSTRAINT IF EXISTS learn_grades_status_check');
        DB::statement(
            'ALTER TABLE learn_grades ADD CONSTRAINT learn_grades_status_check '
            . "CHECK (status IN ('pending', 'in_progress', 'pre_graded', 'graded', 'failed'))"
        );
    }

    private function quotedStatuses(): string
    {
        return implode(', ', array_map(
            static fn (string $v): string => "'" . $v . "'",
            LearnGradeStatus::values(),
        ));
    }
};
