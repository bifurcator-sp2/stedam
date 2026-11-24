<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

/**
 * Ошибка компиляции конкурентной записи агрегата: оптимистичная блокировка
 * по `WHERE id = ? AND status = :oldStatus` не дала совпадений. Доменная (409).
 */
final class ConcurrentModificationException extends RequestedActionDomainException
{
    public static function forId(int $id): self
    {
        return new self("RequestedAction {$id} was modified concurrently");
    }
}
