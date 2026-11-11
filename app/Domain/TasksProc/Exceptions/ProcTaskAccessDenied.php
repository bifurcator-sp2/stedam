<?php

declare(strict_types=1);

namespace App\Domain\TasksProc\Exceptions;

use DomainException;

/**
 * Управлять процедурными заданиями может только администратор.
 */
final class ProcTaskAccessDenied extends DomainException
{
    public static function mutation(int $userId): self
    {
        return new self("User {$userId} is not allowed to create or modify proc tasks");
    }
}
