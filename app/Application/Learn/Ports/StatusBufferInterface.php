<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

/**
 * Буфер событий смены статуса (между логгером и batch-сбросом в БД).
 */
interface StatusBufferInterface
{
    /**
     * @param array<string, mixed> $event
     */
    public function push(array $event): void;

    /**
     * Извлекает до $limit событий (атомарно, голова списка).
     *
     * @return list<array<string, mixed>>
     */
    public function pop(int $limit): array;

    public function size(): int;
}
