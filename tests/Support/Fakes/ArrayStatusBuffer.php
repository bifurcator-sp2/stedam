<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Infrastructure\Learn\LearnStatusBuffer;

/**
 * In-memory замена Redis-буфера для тестов джобы сброса.
 * Наследуется от LearnStatusBuffer невозможно (final readonly + ctor),
 * поэтому используется через интерфейс-подмену не годится — джоба типизирует
 * конкретный класс. Держим тестовый наследник через композицию нельзя,
 * поэтому реализуем тот же публичный контракт в отдельном классе-двойнике
 * и биндим его вместо оригинала.
 */
final class ArrayStatusBuffer extends LearnStatusBuffer
{
    /** @var list<array<string, mixed>> */
    public array $items = [];

    public function __construct()
    {
        // Родительский конструктор не вызываем — Redis не нужен.
    }

    public function push(array $event): void
    {
        $this->items[] = $event;
    }

    public function pop(int $limit): array
    {
        $slice = array_splice($this->items, 0, max(0, $limit));

        return array_values($slice);
    }

    public function size(): int
    {
        return count($this->items);
    }
}
