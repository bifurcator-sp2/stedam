<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTOs;

/**
 * Параметры чтения списка уведомлений текущего получателя.
 *
 *   — notifiableType — класс модели-получателя (`App\Models\User`);
 *   — notifiableId   — id получателя (текущий пользователь);
 *   — search         — нормализованная подстрока для поиска по data.title
 *                      (null — поиска нет).
 */
final readonly class NotificationListFilter
{
    public function __construct(
        public string $notifiableType,
        public int $notifiableId,
        public ?string $search = null,
    ) {}
}
