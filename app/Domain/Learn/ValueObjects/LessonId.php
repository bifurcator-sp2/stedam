<?php

declare(strict_types=1);

namespace App\Domain\Learn\ValueObjects;

use App\Domain\Learn\Exceptions\InvalidId;

final readonly class LessonId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidId('LessonId must be a positive integer');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
