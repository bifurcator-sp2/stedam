<?php

declare(strict_types=1);

namespace App\Application\Notifications\Ports;

/**
 * Write-side стандартных (database) уведомлений Laravel для получателя.
 *
 * Реализация прячет физику таблицы `notifications`: проставление отметки
 * о прочтении (`read_at`).
 */
interface NotificationsWriteInterface
{
    /**
     * Пометить прочитанными уведомления получателя из переданного набора.
     *
     * Чужие id и несуществующие молча игнорируются; уже прочитанные не
     * перезаписываются. Возвращает число реально обновлённых строк.
     *
     * @param  list<string>  $ids
     */
    public function markAsRead(string $notifiableType, int $notifiableId, array $ids): int;
}
