<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\ValueObjects;

use InvalidArgumentException;

/**
 * Payload агрегата — произвольные данные, нужные обработчику (`jsonb`).
 *
 * Правила (раздел 1, 2.4, 2.15 п.3):
 *  - иммутабелен: пишется ОДИН раз при создании агрегата, переходы статуса
 *    и запись голосов его не меняют;
 *  - обязательный ключ `consumers` — список id пользователей-приёмщиков;
 *    отсутствие — доменная ошибка (проверяется в `create()` агрегата);
 *  - `hash()` — только для логов/событий. Идемпотентность в БД обеспечивает
 *    generated column `payload_hash = md5(payload::text)` (jsonb канонизирует
 *    порядок ключей); PHP-hash в INSERT не участвует (2.15 п.21).
 */
final readonly class RequestedActionPayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data)
    {
        if ($data === []) {
            throw new InvalidArgumentException('RequestedAction payload must not be empty');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Стабильный хеш для логов и событий. НЕ совпадает с БД-эквивалентом,
     * пока json_encode сортирует ключи вручную: в БД canonical form даёт jsonb.
     */
    public function hash(): string
    {
        $canonical = $this->data;
        ksort($canonical);

        return md5((string) json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<int>
     */
    public function consumers(): array
    {
        $consumers = $this->data['consumers'] ?? [];

        if (! is_array($consumers)) {
            throw new InvalidArgumentException('payload.consumers must be an array of user ids');
        }

        $result = [];
        foreach ($consumers as $id) {
            $result[] = (int) $id;
        }

        return $result;
    }

    public function isConsumer(int $userId): bool
    {
        return in_array($userId, $this->consumers(), true);
    }
}
