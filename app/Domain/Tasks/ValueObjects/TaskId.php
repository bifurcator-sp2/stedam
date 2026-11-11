<?php

declare(strict_types=1);

namespace App\Domain\Tasks\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор обычного (не процедурного) задания.
 *
 * Физически хранится в lesson_blocks (lesson_id IS NULL, is_proc = false),
 * но домену Tasks это безразлично: он оперирует TaskId.
 */
final readonly class TaskId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("TaskId must be positive, got {$value}");
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
