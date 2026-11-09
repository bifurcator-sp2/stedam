<?php

declare(strict_types=1);

namespace App\Domain\Learn\ValueObjects;

use App\Domain\Learn\Exceptions\InvalidId;

final readonly class UserId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidId('UserId must be a positive integer');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
