<?php

declare(strict_types=1);

namespace App\Listeners\RequestedActions;

use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Domain\RequestedActions\Events\ActionRejected;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Уведомления об отклонении запроса (`pending → rejected`, 2.11).
 *
 * Аналог {@see SendApprovalNotifications}: после commit, содержимое готовит
 * handler (`sendRejectionNotifications`), слушатель лишь достаёт агрегат.
 */
final class SendRejectionNotifications implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private RequestedActionRepositoryInterface $actions,
        private RequestedActionHandlerRegistryInterface $handlers,
    ) {}

    public function handle(ActionRejected $event): void
    {
        $action = $this->actions->find($event->requestedActionId);

        if ($action === null) {
            Log::warning('ActionRejected: requested action not found', [
                'requestedActionId' => $event->requestedActionId,
            ]);

            return;
        }

        $this->handlers->resolve($event->handler)->sendRejectionNotifications($action);
    }
}
