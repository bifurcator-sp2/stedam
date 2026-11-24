<?php

declare(strict_types=1);

namespace App\Listeners\RequestedActions;

use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Domain\RequestedActions\Events\ActionError;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Уведомления об ошибке пайпа (`* → error`, 2.11).
 *
 * После commit; содержимое готовит handler (`sendErrorNotifications`).
 * Источники `ActionError`: `ErrorCollector` (из `SendRequestNotificationsAction`,
 * `HandleApprovedAction`) и stale-recovery (п.18). Сами уведомления не идут
 * через `ErrorCollector`, иначе ошибка уведомления зациклит пайп.
 */
final class SendErrorNotifications implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private RequestedActionRepositoryInterface $actions,
        private RequestedActionHandlerRegistryInterface $handlers,
    ) {}

    public function handle(ActionError $event): void
    {
        $action = $this->actions->find($event->requestedActionId);

        if ($action === null) {
            Log::warning('ActionError: requested action not found', [
                'requestedActionId' => $event->requestedActionId,
            ]);

            return;
        }

        $this->handlers->resolve($event->handler)->sendErrorNotifications($action);
    }
}
