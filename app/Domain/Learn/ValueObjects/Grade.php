<?php

declare(strict_types=1);

namespace App\Domain\Learn\ValueObjects;

use App\Domain\Learn\Exceptions\InvalidGrade;

final readonly class Grade
{
    public function __construct(public float $value)
    {
        if ($value < 0.0 || $value > 100.0) {
            throw new InvalidGrade("Grade must be between 0 and 100, got {$value}");
        }
    }

    public static function fromInt(int $value): self
    {
        return new self((float) $value);
    }

    public function equals(self $other): bool
    {
        return abs($this->value - $other->value) < 0.0001;
    }

    public function __toString(): string
    {
        return number_format($this->value, 2, '.', '');
    }
}
