<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Ports\NotificationsWriteInterface;
use Illuminate\Support\Facades\DB;

/**
 * Write-side стандартных (database) уведомлений Laravel на PostgreSQL.
 *
 * Источник — таблица `notifications` (канал `database`). Прочтение
 * фиксируется в `read_at`; отметка ставится только получателю из
 * `notifiable_type`/`notifiable_id`.
 */
final class EloquentNotificationsWrite implements NotificationsWriteInterface
{
    public function markAsRead(string $notifiableType, int $notifiableId, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('notifications')
            ->where('notifiable_type', $notifiableType)
            ->where('notifiable_id', $notifiableId)
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
