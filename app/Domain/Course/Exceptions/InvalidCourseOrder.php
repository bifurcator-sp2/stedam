<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

final class InvalidCourseOrder extends DomainException
{
    public static function nonPositive(int $order): self
    {
        return new self("Course order must be positive, got {$order}");
    }

    public static function duplicate(int $order): self
    {
        return new self("Course order {$order} is already taken");
    }
}
