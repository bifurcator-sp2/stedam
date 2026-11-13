<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use App\Domain\Course\Exceptions\InvalidNextLessonThreshold;

/**
 * Порог допуска к следующему уроку: доля в (0..1].
 *
 * Правило применения: threshold допуска = max(course.next_lesson_threshold,
 * lesson_type.next_lesson_threshold). Курс может ужесточить требование,
 * но не ослабить тип.
 *
 * Ноль запрещён: «пропустить без сдачи» задаётся отсутствием проверки,
 * а не нулевым порогом.
 */
final readonly class NextLessonThreshold
{
    public function __construct(public float $value)
    {
        if ($value <= 0.0 || $value > 1.0) {
            throw new InvalidNextLessonThreshold(
                "NextLessonThreshold must be in (0.0, 1.0], got {$value}"
            );
        }
    }

    /**
     * Берёт более строгий из двух порогов.
     */
    public function max(self $other): self
    {
        return $this->value >= $other->value ? $this : $other;
    }

    /**
     * Пройден предыдущий урок с такой долей?
     */
    public function isSatisfiedBy(CourseGrade $previousGrade): bool
    {
        return $previousGrade->value > $this->value
            && abs($previousGrade->value - $this->value) > 1e-9;
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
