<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use App\Domain\Course\Exceptions\InvalidCourseGrade;

/**
 * Оценка курса: доля в [0..1].
 *
 * ВАЖНО: масштаб 0..1, а не 0..100 как в Learn\Grade. Нормализация
 * выполняется в Infrastructure-адаптере (на входе: score/100
 * из Learn), чтобы все стратегии курса жили в одной шкале.
 */
final readonly class CourseGrade
{
    public function __construct(public float $value)
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new InvalidCourseGrade("CourseGrade must be in [0.0, 1.0], got {$value}");
        }
    }

    public static function zero(): self
    {
        return new self(0.0);
    }

    public function equals(self $other): bool
    {
        return abs($this->value - $other->value) < 1e-9;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->value > $other->value && !$this->equals($other);
    }

    /**
     * Процент для отображения (0..100).
     */
    public function toPercent(): float
    {
        return $this->value * 100.0;
    }

    public function __toString(): string
    {
        return number_format($this->value, 4, '.', '');
    }
}
