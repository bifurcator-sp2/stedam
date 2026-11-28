<?php

declare(strict_types=1);

namespace App\Application\Notifications\Actions;

use App\Application\Notifications\Ports\NotificationsWriteInterface;

/**
 * Отметка о прочтении уведомлений текущего получателя.
 *
 * Ответственность Action:
 *   — нормализовать набор id (строки, без пустых, без дублей);
 *   — зафиксировать получателя (модель + id) — чужие id отсекает порт;
 *   — делегировать в write-порт.
 *
 * SQL/PostgreSQL-детали сюда не проникают — они в реализации порта.
 */
final readonly class MarkNotificationsReadAction
{
    public function __construct(
        private NotificationsWriteInterface $notifications,
    ) {}

    /**
     * @param  array<array-key, mixed>  $rawIds  «сырой» список id из запроса
     * @return int число реально обновлённых уведомлений
     */
    public function markAsRead(string $notifiableType, int $notifiableId, array $rawIds): int
    {
        $ids = self::normalizeIds($rawIds);

        if ($ids === []) {
            return 0;
        }

        return $this->notifications->markAsRead($notifiableType, $notifiableId, $ids);
    }

    /**
     * @param  array<array-key, mixed>  $rawIds
     * @return list<string>
     */
    private static function normalizeIds(array $rawIds): array
    {
        $ids = [];

        foreach ($rawIds as $raw) {
            if (! is_string($raw)) {
                continue;
            }

            $id = trim($raw);

            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }
}
