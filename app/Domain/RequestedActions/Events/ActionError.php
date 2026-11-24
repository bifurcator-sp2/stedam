<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Events;

use DateTimeImmutable;

/**
 * Действие завершилось ошибкой (* → error).
 * Слушатель: SendErrorNotifications (ShouldQueue).
 */
final readonly class ActionError
{
    public function __construct(
        public int $requestedActionId,
        public string $handler,
        public int $userId,
        public string $errorCode,
        public string $errorDetail,
        public DateTimeImmutable $errorAt,
    ) {}
}
