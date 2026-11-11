<?php

declare(strict_types=1);

namespace App\Domain\TasksProc\Exceptions;

use DomainException;

final class ProcTaskNotFound extends DomainException
{
    public static function withId(int $id): self
    {
        return new self("ProcTask {$id} not found");
    }
}
