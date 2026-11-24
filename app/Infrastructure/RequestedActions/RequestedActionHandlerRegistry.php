<?php

declare(strict_types=1);

namespace App\Infrastructure\RequestedActions;

use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Domain\RequestedActions\Exceptions\HandlerNotFoundException;
use App\Domain\RequestedActions\Handlers\RequestedActionHandler;
use Illuminate\Contracts\Container\Container;

/**
 * Реестр обработчиков: alias → FQCN из `config/requested_actions.php` (2.15 п.15).
 *
 * Alias совпадает со значением колонки `handler` агрегата. Инстанс
 * собирается DI-контейнером, поэтому обработчик может зависеть от портов.
 */
final readonly class RequestedActionHandlerRegistry implements RequestedActionHandlerRegistryInterface
{
    public function __construct(
        private Container $container,
    ) {}

    public function resolve(string $alias): RequestedActionHandler
    {
        /** @var class-string|null $class */
        $class = config("requested_actions.handlers.{$alias}");

        if (! is_string($class) || $class === '' || ! is_a($class, RequestedActionHandler::class, true)) {
            throw HandlerNotFoundException::withAlias($alias);
        }

        $handler = $this->container->make($class);

        if (! $handler instanceof RequestedActionHandler) {
            throw HandlerNotFoundException::withAlias($alias);
        }

        return $handler;
    }

    public function has(string $alias): bool
    {
        /** @var class-string|null $class */
        $class = config("requested_actions.handlers.{$alias}");

        return is_string($class) && $class !== '' && is_a($class, RequestedActionHandler::class, true);
    }

    public function aliases(): array
    {
        /** @var array<string, class-string> $handlers */
        $handlers = (array) config('requested_actions.handlers', []);

        return array_keys($handlers);
    }
}
