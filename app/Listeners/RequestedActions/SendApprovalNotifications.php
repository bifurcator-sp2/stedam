<?php

declare(strict_types=1);

namespace App\Listeners\RequestedActions;

use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Domain\RequestedActions\Events\ActionApproved;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Уведомления о принятии запроса (`pending → approved`, 2.11).
 *
 * Ставится в очередь после commit (`ShouldQueueAfterCommit`): реагирует только
 * на зафиксированное изменение и не тормозит HTTP-ответ. Содержимое сообщений
 * готовит handler (`sendApprovalNotifications`), слушатель только достаёт
 * агрегат и резолвит обработчик.
 *
 * Ошибка доставки не должна ронять воркер: агрегат уже approved, статус
 * уведомлений — забота канала (п.12).
 */
final class SendApprovalNotifications implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private RequestedActionRepositoryInterface $actions,
        private RequestedActionHandlerRegistryInterface $handlers,
    ) {}

    public function handle(ActionApproved $event): void
    {
        $action = $this->actions->find($event->requestedActionId);

        if ($action === null) {
            Log::warning('ActionApproved: requested action not found', [
                'requestedActionId' => $event->requestedActionId,
            ]);

            return;
        }

        $this->handlers->resolve($event->handler)->sendApprovalNotifications($action);
    }
}
