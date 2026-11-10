<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\Ports\StatusBufferInterface;

/**
 * In-memory буфер статусов для тестов (без Redis).
 */
final class InMemoryLearnStatusBuffer implements StatusBufferInterface
{
    /** @var list<array<string, mixed>> */
    public array $items = [];

    /**
     * @param array<string, mixed> $event
     */
    public function push(array $event): void
    {
        $this->items[] = $event;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pop(int $limit): array
    {
        $chunk = array_splice($this->items, 0, max(0, $limit));

        return array_values($chunk);
    }

    public function size(): int
    {
        return count($this->items);
    }
}
