<?php

declare(strict_types=1);

namespace App\Domain\TasksProc\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор процедурного задания.
 *
 * Физически хранится в той же таблице, что и обычное задание,
 * но домен TasksProc об этом не знает: для него это отдельная сущность
 * с отдельными инвариантами (нет привязки к таксономии, есть complexity).
 */
final readonly class ProcTaskId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("ProcTaskId must be positive, got {$value}");
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
