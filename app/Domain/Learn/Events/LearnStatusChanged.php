<?php

declare(strict_types=1);

namespace App\Domain\Learn\Events;

use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use DateTimeImmutable;

/**
 * Доменное событие: статус Прохождения изменился.
 *
 * Единственный источник для StatusLoggerInterface (Application).
 * Не пишется в БД напрямую — Application превращает его в вызов порта.
 */
final readonly class LearnStatusChanged
{
    public function __construct(
        public LearnId $learnId,
        public LessonId $lessonId,
        public LearnStatus $status,
        public DateTimeImmutable $statusDate,
        public ?ScheduleDate $scheduleDate = null,
    ) {}
}
