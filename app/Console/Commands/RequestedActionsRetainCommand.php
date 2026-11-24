<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\RequestedActions\Actions\DropOrphanLeavesAction;
use App\Application\RequestedActions\Actions\RetainActiveLeavesAction;
use Illuminate\Console\Command;

/**
 * Retention активной ветки (2.8.2, 2.12): раз в час.
 *
 * Удаляет листья активной ветки старше `retention.active_months` (спутники
 * votes/attempts → `detachAndDrop`). Перед прогоном и после него добивает
 * сирот от прошлых сбоев.
 */
final class RequestedActionsRetainCommand extends Command
{
    protected $signature = 'requested-actions:retain
                            {--months= : Переопределить retention.active_months}';

    protected $description = 'Удаляет листья активной ветки старше retention.active_months';

    public function handle(
        DropOrphanLeavesAction $orphans,
        RetainActiveLeavesAction $retain,
    ): int {
        $orphansBefore = $orphans->execute();
        $dropped = $retain->execute($this->option('months') !== null ? (int) $this->option('months') : null);
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
