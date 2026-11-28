<?php

declare(strict_types=1);

namespace App\Application\Notifications\Ports;

use App\Application\Notifications\DTOs\NotificationListFilter;
use App\Application\Shared\Pagination\PaginatedResult;

/**
 * Read-side стандартных (database) уведомлений Laravel для конкретного получателя.
 *
 * Реализация прячет физику таблицы `notifications`: фильтр по notifiable,
 * поиск по `data.title`, сортировка от новых к старым.
 */
interface NotificationsQueryInterface
{
    /**
     * Постраничный список уведомлений получателя.
     *
     * Элементы — ассоциативные массивы с ключами id, type, data, created_at, read_at.
     *
     * @return PaginatedResult<array{id: string, type: string, data: array<string, mixed>, created_at: string, read_at: string|null}>
     */
    public function paginate(NotificationListFilter $filter, int $page, int $perPage): PaginatedResult;
}
