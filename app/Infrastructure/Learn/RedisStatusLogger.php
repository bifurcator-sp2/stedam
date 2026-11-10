<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use DateTimeImmutable;

/**
 * Асинхронный логгер статусов: складывает событие в Redis-буфер.
 *
 * Запись в БД выполняет FlushLearnStatusBufferJob (батчем).
 * Вызов из Application-слоя — O(1), без обращения к БД.
 */
final readonly class RedisStatusLogger implements StatusLoggerInterface
{
    public function __construct(
        private LearnStatusBuffer $buffer,
    ) {}

    public function log(
        LearnId $learnId,
        LessonId $lessonId,
        LearnStatus $status,
        DateTimeImmutable $statusDate,
        ?DateTimeImmutable $scheduleDate = null,
    ): void {
        $this->buffer->push([
            'learn_id'      => $learnId->value,
            'lesson_id'     => $lessonId->value,
            'status'        => $status->value,
            'status_date'   => $statusDate->format(DATE_ATOM),
            'schedule_date' => $scheduleDate?->format(DATE_ATOM),
        ]);
    }
}
