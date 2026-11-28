<?php

declare(strict_types=1);

namespace App\Domain\Community\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор пользователя в контексте Community.
 *
 * Отдельный VO на каждый контекст — как Domain\Course\ValueObjects\UserId
 * или Domain\Learn\ValueObjects\UserId: контексты не делят типы, чтобы
 * идентификатор одного домена нельзя было случайно передать в другой.
 *
 * 0 и отрицательные значения недопустимы (нет такого пользователя).
 */
final readonly class UserId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('UserId must be positive');
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
