<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Exceptions;

use DomainException;

final class TaskAccessDenied extends DomainException
{
    public static function notOwner(int $taskId, int $userId): self
    {
        return new self("User {$userId} is not allowed to modify task {$taskId}");
    }
}
