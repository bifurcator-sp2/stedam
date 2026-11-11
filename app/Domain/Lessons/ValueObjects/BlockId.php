<?php

declare(strict_types=1);

namespace App\Domain\Lessons\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор прототипа блока (таблица blocks).
 *
 * Это НЕ LessonBlock: blocks — шаблон (Block), из которого создаются
 * LessonBlock'и. Домен Lessons/LessonBlocks ссылается на него только id.
 */
final readonly class BlockId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("BlockId must be positive, got {$value}");
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
