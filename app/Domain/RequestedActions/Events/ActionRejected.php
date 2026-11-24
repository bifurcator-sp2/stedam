<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Events;

use DateTimeImmutable;

/**
 * Действие отклонено (pending → rejected). Эмитится ровно один раз.
 * Слушатель: SendRejectionNotifications (ShouldQueue).
 */
final readonly class ActionRejected
{
    public function __construct(
        public int $requestedActionId,
        public string $handler,
        public int $userId,
        public DateTimeImmutable $rejectedAt,
        public int $rejectedBy,
    ) {}
}
