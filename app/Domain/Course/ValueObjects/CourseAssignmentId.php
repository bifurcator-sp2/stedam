<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор назначения курса ученику.
 */
final readonly class CourseAssignmentId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("CourseAssignmentId must be positive, got {$value}");
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
