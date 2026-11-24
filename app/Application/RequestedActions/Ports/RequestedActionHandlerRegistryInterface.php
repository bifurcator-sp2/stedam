<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Ports;

use App\Domain\RequestedActions\Exceptions\HandlerNotFoundException;
use App\Domain\RequestedActions\Handlers\RequestedActionHandler;

/**
 * Резолв alias → инстанс обработчика (2.15 п.15).
 *
 * Alias хранится в колонке `handler` агрегата. Карта alias → FQCN живёт
 * в `config/requested_actions.php`, реализация резолвит через DI-контейнер.
 *
 * Неизвестный alias → HandlerNotFoundException (FatalException) → error.
 */
interface RequestedActionHandlerRegistryInterface
{
    /**
     * @throws HandlerNotFoundException
     */
    public function resolve(string $alias): RequestedActionHandler;

    public function has(string $alias): bool;

    /**
     * @return list<string>
     */
    public function aliases(): array;
}
