<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

use App\Domain\RequestedActions\Enums\RequestedActionStatus;

/**
 * Агрегат уже закрыт: попытка выполнить переход, недопустимый из текущего
 * статуса (например, открыть форму принятия для approved агрегата). HTTP → 409.
 *
 * Для registerApproval «закрытость» — это ApprovalResult::CLOSED, а не
 * это исключение (2.15 п.26).
 */
final class RequestedActionAlreadyClosedException extends RequestedActionDomainException
{
    public static function currentStatus(RequestedActionStatus $status): self
    {
        return new self("Requested action is closed (status: {$status->value})");
    }
}
