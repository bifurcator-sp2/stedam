<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\Ports\StatusBufferInterface;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

/**
 * Буфер событий смены статуса в Redis (список).
 *
 * Писатель — RedisStatusLogger (RPUSH, O(1)).
 * Читатель — FlushLearnStatusBufferJob (LPOP пачками через пайплайн).
 */
final readonly class LearnStatusBuffer implements StatusBufferInterface
{
    public function __construct(
        private RedisFactory $redis,
        private string $connection,
        private string $key,
    ) {}

    private function conn(): Connection
    {
        return $this->redis->connection($this->connection);
    }

    /**
     * @param array<string, mixed> $event
     */
    public function push(array $event): void
    {
        $this->conn()->rpush($this->key, json_encode($event, JSON_THROW_ON_ERROR));
    }

    /**
     * Извлекает до $limit событий (LPOP со стороны головы).
     * Атомарно вынимает элементы, чтобы параллельные воркеры не дублировали.
     *
     * @return list<array<string, mixed>>
     */
    public function pop(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $conn = $this->conn();

        // LPOP с count (Redis >= 6.2).
        /** @var list<string>|null $raw */
        $raw = $conn->lpop($this->key, $limit);

        if ($raw === null) {
            return [];
        }

        // На части версий без count вернётся одна строка.
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $events = [];
        foreach ($raw as $item) {
            $decoded = json_decode((string) $item, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    public function size(): int
    {
        return (int) $this->conn()->llen($this->key);
    }
}
