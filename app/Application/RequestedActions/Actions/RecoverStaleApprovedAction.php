<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Application\Shared\Clock;
use App\Application\Shared\TransactionManagerInterface;
use App\Domain\RequestedActions\Events\ActionError;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Stale-recovery для «зависших» approved (2.8.2).
 *
 * Две подгруппы:
 *  - T1: у агрегата нет ни одной попытки handle и он висит дольше
 *    `stale.not_started_hours` → `HANDLE_STALE_NOT_STARTED`;
 *  - T2: попытки есть, но воркер исчерпал retry дольше
 *    `stale.retry_exhausted_days` назад → `HANDLE_STALE_RETRY_EXHAUSTED`.
 *
 * Порядок для каждой строки (2.15 п.1, п.18, п.19):
 *  1) `markStaleError` (status=error, complete не меняется) — отдельная
 *     транзакция;
 *  2) `markCompleteById` (complete=true) — отдельная транзакция: `complete` —
 *     ключ LIST-секционирования, нельзя менять в том же UPDATE;
 *  3) событие `ActionError` — после commit.
 *
 * Кандидаты берутся под `FOR UPDATE SKIP LOCKED`, поэтому параллельный
 * handle-approved не пересекается с recovery.
 */
final readonly class RecoverStaleApprovedAction
{
    public const ERROR_NOT_STARTED = 'HANDLE_STALE_NOT_STARTED';

    public const ERROR_RETRY_EXHAUSTED = 'HANDLE_STALE_RETRY_EXHAUSTED';

    public function __construct(
        private RequestedActionRepositoryInterface $actions,
        private TransactionManagerInterface $transactions,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    /**
     * @return array{T1: int, T2: int} число восстановленных по каждой группе
     */
    public function execute(?int $limit = null): array
    {
        $limit ??= (int) config('requested_actions.workers.batch_size', 100);
        $notStarted = (int) config('requested_actions.stale.not_started_hours', 24) * 3600;
        $retryExhausted = (int) config('requested_actions.stale.retry_exhausted_days', 7) * 86400;

        $stats = ['T1' => 0, 'T2' => 0];

        // Блокировка и классификация — в одной транзакции, затем commit:
        // recover() уже переводит строки отдельными UPDATE, поэтому держать
        // FOR UPDATE дольше не нужно.
        $candidates = $this->transactions->transactional(
            fn (): array => $this->actions->lockStaleApprovedCandidates($notStarted, $retryExhausted, $limit),
        );

        foreach ($candidates as $group => $rows) {
            $errorCode = $group === 'T1' ? self::ERROR_NOT_STARTED : self::ERROR_RETRY_EXHAUSTED;
            $detail = $group === 'T1'
                ? 'Approved action was never handled by a worker'
                : 'Handle retry budget exhausted';

            foreach ($rows as $row) {
                if ($this->recover($row['id'], $row['created_at'], $errorCode, $detail)) {
                    $stats[$group]++;
                }
            }
        }

        return $stats;
    }

    private function recover(int $id, string $createdAt, string $errorCode, string $detail): bool
    {
        $row = $this->transactions->transactional(
            fn (): ?array => $this->actions->markStaleError($id, $createdAt, $errorCode, $detail),
        );

        if ($row === null) {
            return false;
        }

        $this->transactions->transactional(
            fn () => $this->actions->markCompleteById($row['id'], $row['created_at']),
        );

        $this->events->dispatch(new ActionError(
            requestedActionId: $row['id'],
            handler: $row['handler'],
            userId: $row['user_id'],
            errorCode: $errorCode,
            errorDetail: $detail,
            errorAt: $this->clock->now(),
        ));

        return true;
    }
}
