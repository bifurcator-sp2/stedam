<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTOs;

/**
 * Параметры чтения списка уведомлений текущего получателя.
 *
 *   — notifiableType — класс модели-получателя (`App\Models\User`);
 *   — notifiableId   — id получателя (текущий пользователь);
 *   — search         — нормализованная подстрока для поиска по data.title
 *                      (null — поиска нет);
 *   — onlyUnread     — true — только непрочитанные (`read_at IS NULL`),
 *                      false — только прочитанные,
 *                      null — все (поведение по умолчанию).
 */
final readonly class NotificationListFilter
{
    public function __construct(
        public string $notifiableType,
        public int $notifiableId,
        public ?string $search = null,
        public ?bool $onlyUnread = null,
    ) {}
}
