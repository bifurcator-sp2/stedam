<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор темы курса.
 */
final readonly class CourseThemeId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("CourseThemeId must be positive, got {$value}");
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
