<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

/**
 * Приёмщик уже голосовал по этому агрегату. Голос неизменяем
 * (UNIQUE на `requested_action_votes`). HTTP → 409, ActionError не эмитится.
 */
final class AlreadyVotedException extends RequestedActionDomainException
{
    public static function forUser(int $userId, int $actionId): self
    {
        return new self("User {$userId} already voted on requested action {$actionId}");
    }
}
