<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * Диспетчер событий для тестов, которым не нужен контейнер Laravel.
 *
 * Реализует полностью интерфейс Dispatcher, но запоминает только факты
 * dispatch: для проверки «Action опубликовал событие» большего не нужно.
 * Методы регистрации слушателей оставлены пустыми — тесты Application-слоя
 * проверяют публикацию, а не доставку.
 */
final class FakeDispatcher implements Dispatcher
{
    /** @var list<object> */
    private array $events = [];

    public function dispatch($event, $payload = [], $halt = false)
    {
        if (is_array($event)) {
            $this->events = [...$this->events, ...array_values($event)];

            return $event;
        }

        $this->events[] = $event;

        return $event;
    }

    /**
     * Все опубликованные события.
     *
     * @return list<object>
     */
    public function dispatchedEvents(): array
    {
        return $this->events;
    }

    public function hasDispatched(string $eventClass): bool
    {
        return $this->countOf($eventClass) > 0;
    }

    /**
     * Последнее опубликованное событие данного класса (или null).
     */
    public function lastOf(string $eventClass): ?object
    {
        $found = null;

        foreach ($this->events as $event) {
            if ($event::class === $eventClass) {
                $found = $event;
            }
        }

        return $found;
    }

    public function countOf(string $eventClass): int
    {
        return count(array_filter(
            $this->events,
            static fn (object $e): bool => $e::class === $eventClass,
        ));
    }

    // === Методы интерфейса, которые тестам не нужны ===

    public function listen($events, $listener = null): void {}

    public function hasListeners($eventName): bool
    {
        return false;
    }

    public function subscribe($subscriber): void {}

    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    public function push($event, $payload = []): void {}

    public function flush($event): void {}

    public function forget($event): void {}

    public function forgetPushed(): void {}
}
