<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\RequestedActions\Actions\SendRequestNotificationsAction;
use Illuminate\Console\Command;

/**
 * Messaging-воркер: рассылает запросы приёмщикам по агрегатам в статусе
 * created (2.12, 2.15 п.5).
 *
 * Агрегаты берутся батчем под `FOR UPDATE SKIP LOCKED`, каждый
 * обрабатывается в своей транзакции (created → pending). Повторный запуск
 * берёт только оставшиеся created, поэтому команда идемпотентна.
 */
final class RequestedActionsMessagingCommand extends Command
{
    protected $signature = 'requested-actions:messaging
                            {--limit= : Размер батча (по умолчанию из конфига)}';

    protected $description = 'Рассылает уведомления приёмщикам по созданным RequestedAction';

    public function handle(SendRequestNotificationsAction $action): int
    {
        $limit = (int) ($this->option('limit') ?: config('requested_actions.workers.batch_size', 100));

        $processed = $action->execute(max(1, $limit));

        $this->info("Обработано запросов: {$processed}");

        return self::SUCCESS;
    }
}
