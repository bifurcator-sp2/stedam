<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Application\Shared\Clock;
use App\Application\Shared\TransactionManagerInterface;
use App\Domain\RequestedActions\Entities\RequestedAction;
use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\Errors\ErrorCollector;

/**
 * Рассылка запросов приёмщикам для created-агрегатов (2.11, 2.12).
 *
 * Для каждого созданного агрегата:
 *  1. handler резолвится по alias (неизвестный alias → HandlerNotFoundException
 *     → агрегат в error, ActionError);
 *  2. sendRequestNotifications() ставит все сообщения в очередь;
 *  3. created → pending.
 *
 * Батч берётся под FOR UPDATE SKIP LOCKED, поэтому параллельные воркеры
 * строку дважды не обрабатывают. Транзакция своя на каждый агрегат:
 * падение одного не откатывает соседние.
 */
final readonly class SendRequestNotificationsAction
{
    public function __construct(
        private RequestedActionRepositoryInterface $repository,
        private RequestedActionHandlerRegistryInterface $handlers,
        private TransactionManagerInterface $transactions,
        private Clock $clock,
        private ErrorCollector $errors,
    ) {}

    public function execute(int $limit): int
    {
        $processed = 0;

        foreach ($this->repository->nextCreatedBatch($limit) as $action) {
            $this->transactions->transactional(fn () => $this->process($action));
            $processed++;
        }

        return $processed;
    }

    private function process(RequestedAction $action): void
    {
        if ($action->status() !== RequestedActionStatus::Created) {
            return;
        }

        $handler = $this->handlers->resolve($action->handler());
        $now = $this->clock->now();

        try {
            $handler->sendRequestNotifications($action);
        } catch (\Throwable $e) {
            // Доменные ошибки пробрасываются наружу без смены статуса.
            $this->repository->save($this->errors->collect($action, $e, $now));

            return;
        }

        $this->repository->save($action->markPending($now));
    }
}
