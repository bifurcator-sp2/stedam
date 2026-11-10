<?php

use App\Domain\Learn\Enums\LearnStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аудит жизненного цикла Прохождения.
 *
 * Append-only лог: каждая смена статуса learn пишется отдельной строкой.
 * Пишется НЕ напрямую, а через порт StatusLoggerInterface (асинхронный
 * батч: Redis → BatchUpdateStatusesJob). См. Контекст Learn.
 *
 * Источник правды для истории; поля в learns (status/status_date/complete_date)
 * — денормализованный кэш для быстрого чтения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learn_statuses', function (Blueprint $table) {
            $table->id();

            // Прохождение, которому принадлежит событие. Learn не удаляется
            // (только архивируется), поэтому restrictOnDelete безопасен.
            $table->foreignId('learn_id')
                ->constrained('learns')
                ->restrictOnDelete();

            // Денормализация для отчётов: чтобы не джойнить learns ради lesson_id.
            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->restrictOnDelete();

            $table->enum('status', LearnStatus::values());

            // Момент смены статуса — бизнес-время события.
            $table->timestampTz('status_date');

            // Снимок планового времени на момент события (может быть NULL).
            $table->timestampTz('schedule_date')->nullable();

            // Технический timestamp записи строки (для дебага/GC).
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['learn_id', 'status_date'], 'learn_statuses_learn_date_idx');
            $table->index('lesson_id', 'learn_statuses_lesson_id_idx');
            $table->index('status', 'learn_statuses_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learn_statuses');
    }
};
