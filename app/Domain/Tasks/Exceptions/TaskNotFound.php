<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Exceptions;

use DomainException;

final class TaskNotFound extends DomainException
{
    public static function withId(int $id): self
    {
        return new self("Task {$id} not found");
    }
}
