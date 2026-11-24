<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Application\RequestedActions\Ports\RequestedActionHandleAttemptsRepositoryInterface;
use App\Application\RequestedActions\Ports\RequestedActionVotesRepositoryInterface;
use App\Application\Shared\Clock;
use App\Infrastructure\RequestedActions\RequestedActionPartitionManager;

/**
 * Prune завершённой ветки (2.8.2).
 *
 * Листья завершённой ветки старше `retention.complete_days` дней удаляются
 * целиком (спутники по created_at → `detachAndDrop`). Жизненный цикл:
 * `complete=true` → живёт в завершённой ветке `complete_days` → удаляется.
 *
 * Листья, у которых остались неснятые сироты от прошлого падения, добиваются
 * `dropOrphan()` в отдельной фазе (см. `RequestedActionPartitionManager::orphanLeaves`).
 */
final readonly class PruneCompleteLeavesAction
{
    public function __construct(
        private RequestedActionPartitionManager $partitions,
        private RequestedActionVotesRepositoryInterface $votes,
        private RequestedActionHandleAttemptsRepositoryInterface $attempts,
        private Clock $clock,
    ) {}

    /**
     * @return list<string> имена удалённых листьев
     */
    public function execute(?int $days = null): array
    {
        $days ??= (int) config('requested_actions.retention.complete_days', 30);
        $cutoff = $this->clock->now()->modify("-{$days} days");

        $dropped = [];

        foreach ($this->partitions->leaves(RequestedActionPartitionManager::COMPLETE) as $leaf) {
            [$from, $to] = $this->partitions->leafBounds($leaf);

            if ($to > $cutoff) {
                continue;
            }

            $this->votes->deleteForLeaf($from, $to);
            $this->attempts->deleteForLeaf($from, $to);
            $this->partitions->detachAndDrop($leaf);

            $dropped[] = $leaf;
        }

        return $dropped;
    }
}
