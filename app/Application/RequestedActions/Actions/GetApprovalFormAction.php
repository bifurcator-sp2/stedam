<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\Exceptions\NotAConsumerException;
use App\Domain\RequestedActions\Exceptions\RequestedActionAlreadyClosedException;
use App\Domain\RequestedActions\Exceptions\RequestedActionDomainException;
use App\Domain\RequestedActions\Exceptions\RequestedActionNotFoundException;
use App\Domain\RequestedActions\ValueObjects\UserId;

/**
 * Read-side формы принятия: `canGetApprovalForm()` + `getApprovalForm()` (раздел 1).
 *
 * Порядок проверок:
 *  1. агрегат найден (404);
 *  2. агрегат ещё pending (иначе 409 — «запрос уже закрыт»);
 *  3. текущий пользователь ∈ payload.consumers (иначе 403 — до вызова handler'а,
 *     так же, как это сделал бы registerApproval).
 */
final readonly class GetApprovalFormAction
{
    public function __construct(
        private RequestedActionRepositoryInterface $repository,
        private RequestedActionHandlerRegistryInterface $handlers,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(int $id, UserId $responder, array $arguments): array
    {
        $action = $this->repository->find($id);

        if ($action === null) {
            throw RequestedActionNotFoundException::withId($id);
        }

        if ($action->status() !== RequestedActionStatus::Pending) {
            throw RequestedActionAlreadyClosedException::currentStatus($action->status());
        }

        if (! $action->payload()->isConsumer($responder->value)) {
            throw NotAConsumerException::forUser($responder->value, $id);
        }

        $handler = $this->handlers->resolve($action->handler());

        if (! $handler->canGetApprovalForm($action, $responder, $arguments)) {
            throw new RequestedActionDomainException(
                "Approval form is not available for user {$responder->value}"
            );
        }

        return $handler->getApprovalForm($action, $responder, $arguments);
    }
}
