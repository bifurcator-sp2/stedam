<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use DateTimeImmutable;

interface StatusLoggerInterface
{
    public function log(
        LearnId $learnId,
        LessonId $lessonId,
        LearnStatus $status,
        DateTimeImmutable $statusDate,
        ?DateTimeImmutable $scheduleDate = null,
    ): void;
}
