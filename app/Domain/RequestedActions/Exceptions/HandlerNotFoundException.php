<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

/**
 * Alias обработчика из `payload`/колонки `handler` не найден в реестре.
 * Постоянная ошибка → агрегат уходит в error (2.15 п.15).
 */
final class HandlerNotFoundException extends RequestedActionFatalException
{
    public static function withAlias(string $alias): self
    {
        return new self("RequestedAction handler '{$alias}' not found");
    }
}
