<?php

declare(strict_types=1);

namespace App\Domain\LessonBlocks\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор блока урока (строка таблицы lesson_blocks).
 *
 * Одна сущность LessonBlock играет две роли:
 *   — собственный блок урока (lesson_id заполнен);
 *   — глобальное задание (lesson_id = NULL).
 * Домен LessonBlocks отвечает за ОБЕ роли, т.к. физически это одна
 * сущность; различие фиксируется полем LessonBlock::isGlobal().
 */
final readonly class LessonBlockId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("LessonBlockId must be positive, got {$value}");
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
