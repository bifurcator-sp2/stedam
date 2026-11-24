<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\RequestedActions\Actions\DropOrphanLeavesAction;
use App\Application\RequestedActions\Actions\PruneCompleteLeavesAction;
use Illuminate\Console\Command;

/**
 * Prune завершённой ветки (2.8.2, 2.12): раз в сутки.
 *
 * Удаляет листья завершённой ветки старше `retention.complete_days`
 * (спутники votes/attempts → `detachAndDrop`).
 */
final class RequestedActionsPruneCompleteCommand extends Command
{
    protected $signature = 'requested-actions:prune-complete
                            {--days= : Переопределить retention.complete_days}';

    protected $description = 'Удаляет листья завершённой ветки старше retention.complete_days';

    public function handle(
        DropOrphanLeavesAction $orphans,
        PruneCompleteLeavesAction $prune,
    ): int {
        $orphansBefore = $orphans->execute();
        $dropped = $prune->execute($this->option('days') !== null ? (int) $this->option('days') : null);
        $orphansAfter = $orphans->execute();

        $this->info(sprintf(
            'Сирот до: %d, удалено листьев: %d, сирот после: %d',
            count($orphansBefore),
            count($dropped),
            count($orphansAfter),
        ));

        foreach ($dropped as $leaf) {
            $this->line("  dropped: {$leaf}");
        }

        return self::SUCCESS;
    }
}
