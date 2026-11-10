<?php

use App\Domain\Learn\Enums\LearnStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Приводит learns к схеме жизненного цикла Прохождения.
 *
 * - lesson (jsonb) → lesson_snapshot (varchar 64): хранится SHA-256 хэш снапшота,
 *   сам снапшот — в приватном S3-бакете (Kонтекст Learn о его содержимом не знает).
 * - complete → complete_date (timestamptz): денормализованный момент перехода в done,
 *   держим в learns ради поиска; источник правды — learn_statuses.
 * - добавлены status, status_date, schedule_date, graded_by, answers, archived_at.
 *
 * Текущие данные learns незначимы (пробная модель) ⇒ lesson/complete дропаем без переноса.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learns', function (Blueprint $table) {
            $table->dropColumn(['lesson', 'complete']);
        });

        Schema::table('learns', function (Blueprint $table) {
            // Хэш снапшота (SHA-256 hex). NULL до перехода в in_progress.
            $table->char('lesson_snapshot', 64)->nullable()->after('lesson_id');

            // Денормализованный указатель текущего статуса.
            $table->enum('status', LearnStatus::values())
                ->default(LearnStatus::Created->value)
                ->after('lesson_snapshot');

            // Момент последней смены статуса; источник правды — learn_statuses.
            $table->timestampTz('status_date')->nullable()->after('status');

            // Плановое время. Канонично здесь, снимок — в learn_statuses.
            $table->timestampTz('schedule_date')->nullable()->after('status_date');

            // Кто выставил оценку. NULL — автоматическая оценка системой.
            $table->foreignId('graded_by')
                ->nullable()
                ->after('score')
                ->constrained('users')
                ->nullOnDelete();

            // Ответы ученика.
            $table->jsonb('answers')->nullable()->after('graded_by');

            // Денормализованное время завершения (перехода в done).
            $table->timestampTz('complete_date')->nullable()->after('answers');

            // Архив.
            $table->timestampTz('archived_at')->nullable()->after('complete_date');
        });

        Schema::table('learns', function (Blueprint $table) {
            $table->index('lesson_snapshot', 'learns_lesson_snapshot_idx');
            $table->index('schedule_date', 'learns_schedule_date_idx');
            $table->index('status_date', 'learns_status_date_idx');
            $table->index('archived_at', 'learns_archived_at_idx');
            $table->index(['user_id', 'status'], 'learns_user_status_idx');
            $table->index('graded_by', 'learns_graded_by_idx');
        });
    }

    public function down(): void
    {
        Schema::table('learns', function (Blueprint $table) {
            $table->dropIndex('learns_lesson_snapshot_idx');
            $table->dropIndex('learns_schedule_date_idx');
            $table->dropIndex('learns_status_date_idx');
            $table->dropIndex('learns_archived_at_idx');
            $table->dropIndex('learns_user_status_idx');
            $table->dropIndex('learns_graded_by_idx');
        });

        Schema::table('learns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('graded_by');
            $table->dropColumn([
                'lesson_snapshot',
                'status',
                'status_date',
                'schedule_date',
                'answers',
                'complete_date',
                'archived_at',
            ]);
        });

        Schema::table('learns', function (Blueprint $table) {
            $table->jsonb('lesson')->nullable();
            $table->timestampTz('complete')->nullable()->index();
        });
    }
};
