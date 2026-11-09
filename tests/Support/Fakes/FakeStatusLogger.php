<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use DateTimeImmutable;

final class FakeStatusLogger implements StatusLoggerInterface
{
    /** @var list<array{learnId: int, status: LearnStatus, statusDate: DateTimeImmutable}> */
    public array $events = [];

    public function log(
        LearnId $learnId,
        LessonId $lessonId,
        LearnStatus $status,
        DateTimeImmutable $statusDate,
        ?DateTimeImmutable $scheduleDate = null,
    ): void {
        $this->events[] = [
            'learnId'    => $learnId->value,
            'status'     => $status,
            'statusDate' => $statusDate,
        ];
    }
}
