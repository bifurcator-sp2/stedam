<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use App\Domain\Course\Exceptions\InvalidTimeGapMultiplier;

/**
 * Множитель паузы (time_gap) между уроками курса.
 *
 * Курс масштабирует паузу, заданную на уроке. Допустимый диапазон —
 * [0.0, 100.0]: 0 означает «без паузы», 1.0 — «не менять паузу урока»
 * (дефолт курса).
 */
final readonly class TimeGapMultiplier
{
    public const MIN = 0.0;

    public const MAX = 100.0;

    public function __construct(public float $value)
    {
        if (!is_finite($value) || $value < self::MIN || $value > self::MAX) {
            throw new InvalidTimeGapMultiplier(
                'TimeGapMultiplier must be a finite number in [0, 100], got ' . $value
            );
        }
    }

    public static function default(): self
    {
        return new self(1.0);
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
