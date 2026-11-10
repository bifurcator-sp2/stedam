<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Models\LearnStatus as LearnStatusModel;
use DateTimeImmutable;

/**
 * Синхронная запись события смены статуса в append-only лог learn_statuses.
 *
 * Заглушка-реализация порта: пишет сразу в БД. Асинхронный батч
 * (Redis → BatchUpdateStatusesJob) придёт отдельным механизмом.
 * Контракт порта не меняется.
 */
final readonly class DbStatusLogger implements StatusLoggerInterface
{
    public function log(
        LearnId $learnId,
        LessonId $lessonId,
        LearnStatus $status,
        DateTimeImmutable $statusDate,
        ?DateTimeImmutable $scheduleDate = null,
    ): void {
        $model = new LearnStatusModel();
        $model->learn_id      = $learnId->value;
        $model->lesson_id     = $lessonId->value;
        $model->status        = $status;
        $model->status_date   = $statusDate;
        $model->schedule_date = $scheduleDate;
        $model->save();
    }
}
