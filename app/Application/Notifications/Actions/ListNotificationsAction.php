<?php

declare(strict_types=1);

namespace App\Application\Notifications\Actions;

use App\Application\Notifications\DTOs\NotificationListFilter;
use App\Application\Notifications\Ports\NotificationsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;

/**
 * Чтение списка уведомлений текущего получателя по «сырым» параметрам запроса.
 *
 * Ответственность Action:
 *   — нормализовать `search` (обрезка, пустая строка → поиска нет);
 *   — зафиксировать получателя (модель + id);
 *   — делегировать в read-порт.
 *
 * SQL/PostgreSQL-детали сюда не проникают — они в реализации порта.
 */
final readonly class ListNotificationsAction
{
    public function __construct(
        private NotificationsQueryInterface $notifications,
    ) {}

    /**
     * @param array<string, mixed> $input query-параметры запроса
     * @return PaginatedResult<array{id: string, type: string, data: array<string, mixed>}>
     */
    public function paginate(
        string $notifiableType,
        int $notifiableId,
        array $input,
        int $page,
        int $perPage,
    ): PaginatedResult {
        $filter = new NotificationListFilter(
            notifiableType: $notifiableType,
            notifiableId: $notifiableId,
            search: self::buildSearch($input['search'] ?? null),
        );

        return $this->notifications->paginate($filter, $page, $perPage);
    }

    /**
     * Пустая строка — «поиска нет».
     */
    private static function buildSearch(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $search = trim($raw);

        return $search === '' ? null : $search;
    }
}
