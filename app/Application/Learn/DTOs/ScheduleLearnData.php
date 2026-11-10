<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Учитель планирует прохождение ученику (assign/schedule).
 *
 * targetUserId — ученик (должен быть в scope учителя).
 * scheduleDate === null — «assign» (created); иначе — «schedule» (scheduled).
 */
final readonly class ScheduleLearnData
{
    public function __construct(
        public UserId $viewerId,
        public UserId $targetUserId,
        public LessonId $lessonId,
        public ?ScheduleDate $scheduleDate = null,
    ) {}
}
