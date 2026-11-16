<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use App\Domain\Course\Exceptions\InvalidThresholdMultiplier;

/**
 * Множитель базового порога допуска к следующему уроку курса.
 *
 * Базовый порог специфичен для типа урока (lesson_types.next_lesson_threshold),
 * курс лишь масштабирует его: threshold = base(type) * multiplier.
 *
 * Допустимые значения — строго положительные (0, +∞). Дефолт курса — 1.0,
 * то есть «не менять требование типа».
 */
final readonly class ThresholdMultiplier
{
    public function __construct(public float $value)
    {
        if (!is_finite($value) || $value <= 0.0) {
            throw new InvalidThresholdMultiplier(
                "ThresholdMultiplier must be a positive finite number, got {$value}"
            );
        }
    }

    public static function default(): self
    {
        return new self(1.0);
    }

    public function multiply(NextLessonThreshold $base): float
    {
        return $base->value * $this->value;
    }

    public function equals(self $other): bool
    {
        return abs($this->value - $other->value) < 1e-9;
    }

    public function __toString(): string
    {
        return number_format($this->value, 4, '.', '');
    }
}
