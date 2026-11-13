<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use InvalidArgumentException;

/**
 * Пользователь (автор курса, ученик, назначивший).
 *
 * Собственный VO контекста Course по конвенции проекта: контексты не
 * зависят друг от друга через ValueObjects (см. Lessons\ValueObjects\LessonId).
 * Связывание между контекстами — в Infrastructure.
 */
final readonly class UserId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("UserId must be positive, got {$value}");
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
