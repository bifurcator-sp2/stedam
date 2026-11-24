<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Идентичность строки агрегата в партиционированной таблице (раздел 2.7).
 *
 * Состав `(id, complete, created_at)` — полный ключ строки
 * `requested_actions` (PK партиционированной таблицы обязан включать ключи
 * секционирования). Используется как ключ:
 *  - UNIQUE таблицы `requested_action_votes` (один голос на приёмщика);
 *  - INDEX таблицы `requested_action_handle_attempts`;
 *  - фильтра `deleteForLeaf()` при retention.
 *
 * Собирается из сохранённого агрегата: `RequestedAction::ref()`.
 * У несохранённого агрегата id нет — `ref()` бросает \LogicException.
 */
final readonly class RequestedActionRef
{
    public function __construct(
        public int $id,
        public bool $complete,
        public DateTimeImmutable $createdAt,
    ) {
        if ($id <= 0) {
            throw new InvalidArgumentException("RequestedActionRef id must be positive, got {$id}");
        }
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id
            && $this->complete === $other->complete
            && $this->createdAt == $other->createdAt;
    }
}
