<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Events;

use DateTimeImmutable;

/**
 * Действие физически выполнено (approved → done).
 * Слушатель: SendDoneNotifications (ShouldQueue).
 */
final readonly class ActionDone
{
    public function __construct(
        public int $requestedActionId,
        public string $handler,
        public int $userId,
        public DateTimeImmutable $doneAt,
    ) {}
}
