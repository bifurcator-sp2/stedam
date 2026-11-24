<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\ValueObjects;

use InvalidArgumentException;

/**
 * Пользователь контекста RequestedAction (инициатор, приёмщик).
 *
 * Собственный VO по конвенции проекта: контексты не переиспользуют VO друг
 * друга (см. Course\ValueObjects\UserId, Lessons\ValueObjects\UserId) —
 * связывание между контекстами живёт в Infrastructure.
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
