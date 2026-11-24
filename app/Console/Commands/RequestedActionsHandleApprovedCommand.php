<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\RequestedActions\Actions\HandleApprovedAction;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use Illuminate\Console\Command;
use Throwable;

/**
 * Handle-воркер: физически выполняет approved-агрегаты (2.12).
 *
 * Батч approved берётся под `FOR UPDATE SKIP LOCKED`; каждый агрегат
 * обрабатывается {@see HandleApprovedAction} (идемпотентный handle,
 * фиксация attempt, события). Ошибка одного агрегата не останавливает
 * остальные — она уже записана в error/attempt внутри действия.
 */
final class RequestedActionsHandleApprovedCommand extends Command
{
    protected $signature = 'requested-actions:handle-approved
                            {--limit= : Размер батча (по умолчанию из конфига)}
                            {--dry-run : Только показать количество кандидатов}';

    protected $description = 'Выполняет approved RequestedAction (физическое действие handler)';

    public function handle(
        RequestedActionRepositoryInterface $actions,
        HandleApprovedAction $handler,
    ): int {
        $limit = (int) ($this->option('limit') ?: config('requested_actions.workers.batch_size', 100));
        $limit = max(1, $limit);

        if ((bool) $this->option('dry-run')) {
            $count = count($actions->nextApprovedBatch($limit));
            $this->info("Кандидатов на выполнение: {$count}");

            return self::SUCCESS;
        }

        $batch = $actions->nextApprovedBatch($limit);
        $done = 0;
        $failed = 0;

        foreach ($batch as $action) {
            $id = $action->id()?->value;

            if ($id === null) {
                continue;
            }

            try {
                if ($handler->execute($id)) {
                    $done++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Агрегат {$id}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Выполнено: %d, ошибок: %d из %d',
            $done,
            $failed,
            count($batch),
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
