<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use App\Domain\Course\Exceptions\InvalidCompletion;

/**
 * Пройденность курса: доля оценённых уроков от общего количества.
 * Хранится в [0..1], отображается в процентах.
 */
final readonly class CourseCompletion
{
    public function __construct(public float $value)
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new InvalidCompletion("Completion must be in [0.0, 1.0], got {$value}");
        }
    }

    public static function zero(): self
    {
        return new self(0.0);
    }

    public static function full(): self
    {
        return new self(1.0);
    }

    /**
     * Доля: graded / total. total = 0 → 0.0 (пустой курс считается «не пройден»).
     */
    public static function ratio(int $graded, int $total): self
    {
        if ($graded < 0 || $total < 0) {
            throw new InvalidCompletion("Counts must be non-negative, got graded={$graded}, total={$total}");
        }

        if ($graded > $total) {
            throw new InvalidCompletion("Graded count ({$graded}) cannot exceed total ({$total})");
        }

        return $total === 0 ? self::zero() : new self($graded / $total);
    }

    public function equals(self $other): bool
    {
        return abs($this->value - $other->value) < 1e-9;
    }

    public function isComplete(): bool
    {
        return abs($this->value - 1.0) < 1e-9;
    }

    public function toPercent(): float
    {
        return $this->value * 100.0;
    }

    public function __toString(): string
    {
        return number_format($this->value, 4, '.', '');
    }
}
