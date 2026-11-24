<?php

declare(strict_types=1);

namespace App\Listeners\RequestedActions;

use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Domain\RequestedActions\Events\ActionDone;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Уведомления о выполнении действия (`approved → done`, 2.11).
 *
 * После commit; содержимое готовит handler (`sendDoneNotifications`).
 */
final class SendDoneNotifications implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private RequestedActionRepositoryInterface $actions,
        private RequestedActionHandlerRegistryInterface $handlers,
    ) {}

    public function handle(ActionDone $event): void
    {
        $action = $this->actions->find($event->requestedActionId);

        if ($action === null) {
            Log::warning('ActionDone: requested action not found', [
                'requestedActionId' => $event->requestedActionId,
            ]);

            return;
        }

        $this->handlers->resolve($event->handler)->sendDoneNotifications($action);
    }
}
