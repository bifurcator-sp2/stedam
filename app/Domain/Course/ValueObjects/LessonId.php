<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор урока из контекста Lessons.
 *
 * Собственный VO: Course и Learn/Lessons связываются через Infrastructure
 * (маппинг), а не через VO. Отличие от Learn\LessonId: здесь lesson_id всегда
 * физический (>= 1), сентинела "нет урока" не бывает — связь курса с уроком
 * либо есть, либо её нет.
 */
final readonly class LessonId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("LessonId must be positive, got {$value}");
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
