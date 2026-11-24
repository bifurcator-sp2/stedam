<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Events;

use DateTimeImmutable;

/**
 * Действие одобрено кворумом (pending → approved). Эмитится ровно один раз.
 * Слушатель: SendApprovalNotifications (ShouldQueue).
 */
final readonly class ActionApproved
{
    public function __construct(
        public int $requestedActionId,
        public string $handler,
        public int $userId,
        public DateTimeImmutable $approvedAt,
        public int $approvedBy,
    ) {}
}
