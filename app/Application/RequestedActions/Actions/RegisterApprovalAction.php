<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Application\Shared\Clock;
use App\Application\Shared\TransactionManagerInterface;
use App\Domain\RequestedActions\Entities\RequestedAction;
use App\Domain\RequestedActions\Enums\ApprovalResult;
use App\Domain\RequestedActions\Events\ActionApproved;
use App\Domain\RequestedActions\Events\ActionRejected;
use App\Domain\RequestedActions\Exceptions\RequestedActionNotFoundException;
use App\Domain\RequestedActions\ValueObjects\UserId;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Голос приёмщика по запросу (2.4.2, 2.6).
 *
 * Application даёт механическую обвязку, решение принимает handler:
 *  1. транзакция + SELECT ... FOR UPDATE — сериализует конкурентные голоса;
 *  2. `handler->registerApproval()` пишет голос, пересчитывает кворум и
 *     переводит статус (возвращает новый экземпляр агрегата);
 *  3. ACCEPTED/DECLINED → `save()` с оптимистичным `WHERE status = 'pending'`
 *     (0 строк → ConcurrentModificationException, событие не эмитится);
 *  4. после commit — ровно одно событие: ActionApproved или ActionRejected.
 *
 * WAITING/CLOSED событий не дают.
 */
final readonly class RegisterApprovalAction
{
    public function __construct(
        private RequestedActionRepositoryInterface $repository,
        private RequestedActionHandlerRegistryInterface $handlers,
        private EnsureRequestedActionPartitionAction $partitions,
        private TransactionManagerInterface $transactions,
        private Dispatcher $events,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(int $id, UserId $responder, array $arguments): ApprovalResult
    {
        $outcome = $this->transactions->transactional(function () use ($id, $responder, $arguments) {
            $action = $this->repository->findForUpdate($id);

            if ($action === null) {
                throw RequestedActionNotFoundException::withId($id);
            }

            $handler = $this->handlers->resolve($action->handler());
            $outcome = $handler->registerApproval($action, $responder, $arguments);

            if ($outcome->result->isDecisive()) {
                // Решение уже принято: pending → approved/rejected.
                $this->repository->save($outcome->action);
            }

            return $outcome;
        });

        // Переезд в завершённую ветку (rejected): `complete` — ключ
        // партиционирования LIST, поэтому это отдельный UPDATE и отдельная
        // транзакция, а DDL листа завершённой ветки выполняется между ними
        // (как в HandleApprovedAction::saveDone).
        if ($outcome->result->isDecisive() && $outcome->action->isComplete()) {
            $this->partitions->executeComplete(
                $outcome->action->createdAt() ?? $this->clock->now(),
            );

            $this->repository->markComplete($outcome->action);
        }

        $action = $outcome->action;
        $now = $this->clock->now();
        $id = $action->id()?->value ?? $id;

        match ($outcome->result) {
            ApprovalResult::ACCEPTED => $this->events->dispatch(new ActionApproved(
                requestedActionId: $id,
                handler: $action->handler(),
                userId: $action->userId()->value,
                approvedAt: $action->updatedAt() ?? $now,
                approvedBy: $responder->value,
            )),
            ApprovalResult::DECLINED => $this->events->dispatch(new ActionRejected(
                requestedActionId: $id,
                handler: $action->handler(),
                userId: $action->userId()->value,
                rejectedAt: $action->updatedAt() ?? $now,
                rejectedBy: $responder->value,
            )),
            ApprovalResult::WAITING, ApprovalResult::CLOSED => null,
        };

        return $outcome->result;
    }

    /**
     * Read-side для формы принятия: агрегат нужен обработчику (canGetApprovalForm).
     */
    public function find(int $id): RequestedAction
    {
        $action = $this->repository->find($id);

        if ($action === null) {
            throw RequestedActionNotFoundException::withId($id);
        }

        return $action;
    }
}
