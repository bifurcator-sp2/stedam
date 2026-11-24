<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Domain\RequestedActions\Entities\RequestedAction;
use App\Infrastructure\RequestedActions\RequestedActionPartitionManager;
use DateTimeImmutable;

/**
 * Гарантирует наличие помесячного листа под created_at агрегата (2.8).
 *
 * Вызывается перед INSERT активного агрегата и перед сдвигом строки в
 * завершённую ветку: без листа PG отвергает INSERT («no partition of
 * relation found for row»).
 *
 * Действие инфраструктурное (DDL) и требует отдельной транзакции:
 * `CREATE TABLE ... PARTITION OF` нельзя выполнять в той же транзакции,
 * что ждёт блокировки на родителе из DETACH CONCURRENTLY.
 */
final readonly class EnsureRequestedActionPartitionAction
{
    public function __construct(
        private RequestedActionPartitionManager $partitions,
    ) {}

    public function executeActive(DateTimeImmutable $moment): string
    {
        return $this->partitions->ensureActiveFor($moment);
    }

    public function executeComplete(DateTimeImmutable $moment): string
    {
        return $this->partitions->ensureCompleteFor($moment);
    }

    public function executeFor(RequestedAction $action): string
    {
        $moment = $action->createdAt() ?? new DateTimeImmutable('now');

        return $action->isComplete()
            ? $this->partitions->ensureCompleteFor($moment)
            : $this->partitions->ensureActiveFor($moment);
    }
}
