<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\RequestedActions\Actions\RecoverStaleApprovedAction;
use Illuminate\Console\Command;

/**
 * Stale-recovery «зависших» approved (2.8.2, 2.12): раз в сутки.
 *
 * T1 — approved без попыток старше `stale.not_started_hours`; T2 — approved
 * с попытками, retry исчерпан `stale.retry_exhausted_days` назад. Для каждой
 * строки: error → complete=true отдельными UPDATE → событие `ActionError`.
 */
final class RequestedActionsStaleRecoveryCommand extends Command
{
    protected $signature = 'requested-actions:stale-recovery
                            {--limit= : Размер батча (по умолчанию из конфига)}';

    protected $description = 'Переводит зависшие approved RequestedAction в error и завершает их';

    public function handle(RecoverStaleApprovedAction $recovery): int
    {
        $limit = (int) ($this->option('limit') ?: config('requested_actions.workers.batch_size', 100));

        $stats = $recovery->execute(max(1, $limit));

        $this->info(sprintf(
            'Не начато (T1): %d, retry исчерпан (T2): %d',
            $stats['T1'],
            $stats['T2'],
        ));

        return self::SUCCESS;
    }
}
