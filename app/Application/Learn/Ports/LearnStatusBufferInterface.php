<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

/**
 * Буфер событий смены статуса (async-путь логгера).
 */
interface LearnStatusBufferInterface
{
    /**
     * @param array<string, mixed> $event
     */
    public function push(array $event): void;

    /**
     * Атомарно извлекает до $limit событий.
     *
     * @return list<array<string, mixed>>
     */
    public function pop(int $limit): array;

    public function size(): int;
}
