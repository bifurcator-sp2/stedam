<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

/**
 * Пользователь пытается принять решение по действию, адресатом которого
 * он не является (id ∉ payload.consumers). HTTP → 403, ActionError не эмитится.
 */
final class NotAConsumerException extends RequestedActionDomainException
{
    public static function forUser(int $userId, int $actionId): self
    {
        return new self("User {$userId} is not a consumer of requested action {$actionId}");
    }
}
