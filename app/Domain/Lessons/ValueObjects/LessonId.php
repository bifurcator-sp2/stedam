<?php

declare(strict_types=1);

namespace App\Domain\Lessons\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор урока.
 *
 * Отдельный VO от Domain\Learn\ValueObjects\LessonId намеренно:
 * контексты Lessons и Learn не должны зависеть друг от друга через VO.
 * Связывание — в Infrastructure при маппинге.
 *
 * Сентинел `none()` (value = 0) означает «блок не принадлежит уроку»
 * (глобальное задание). Это не «идентификатор», а маркер роли:
 * проверяется через isNone().
 */
final readonly class LessonId
{
    public function __construct(public int $value)
    {
        if ($value < 0) {
            throw new InvalidArgumentException("LessonId must be >= 0, got {$value}");
        }
    }

    public static function none(): self
    {
        return new self(0);
    }

    public function isNone(): bool
    {
        return $this->value === 0;
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
