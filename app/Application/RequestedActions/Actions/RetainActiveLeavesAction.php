<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Application\RequestedActions\Ports\RequestedActionHandleAttemptsRepositoryInterface;
use App\Application\RequestedActions\Ports\RequestedActionVotesRepositoryInterface;
use App\Application\Shared\Clock;
use App\Infrastructure\RequestedActions\RequestedActionPartitionManager;

/**
 * Retention активной ветки (2.8.2).
 *
 * Листья активной ветки старше `retention.active_months` месяцев удаляются
 * целиком: сначала спутники (голоса, attempts) по диапазону created_at,
 * затем лист отсоединяется и удаляется (`DETACH ... FINALIZE`, `DETACH
 * CONCURRENTLY`, `DROP` — каждый шаг отдельная транзакция).
 *
 * Граница «старше N месяцев»: лист исключается из текущей активной ветки,
 * если верхняя граница месяца (from + 1 месяц) ≤ начала месяца, который
 * находится `active_months` назад. Текущий и `active_months - 1` предыдущих
 * месяцев не трогаются.
 */
final readonly class RetainActiveLeavesAction
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
    public function execute(?int $months = null): array
    {
        $months ??= (int) config('requested_actions.retention.active_months', 6);
        $cutoff = $this->clock->now()->modify("first day of -{$months} month")->setTime(0, 0);

        $dropped = [];

        foreach ($this->partitions->leaves(RequestedActionPartitionManager::ACTIVE) as $leaf) {
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
