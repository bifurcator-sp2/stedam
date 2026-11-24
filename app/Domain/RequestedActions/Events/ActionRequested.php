<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Events;

use DateTimeImmutable;

/**
 * Запрос на действие создан (после INSERT в created).
 *
 * Публикуется CreateRequestedActionAction. Слушателей НЕТ: уведомление
 * приёмщикам рассылает requested-actions:messaging worker по статусу created
 * (2.10, 2.15 п.5).
 */
final readonly class ActionRequested
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public int $requestedActionId,
        public string $handler,
        public int $userId,
        public array $arguments,
        public DateTimeImmutable $createdAt,
    ) {}
}
