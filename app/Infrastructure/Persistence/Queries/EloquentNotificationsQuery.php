<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries;

use App\Application\Notifications\DTOs\NotificationListFilter;
use App\Application\Notifications\Ports\NotificationsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Чтение стандартных (database) уведомлений Laravel на PostgreSQL.
 *
 * Источник — таблица `notifications` (канал `database`). Колонка `data` —
 * jsonb, поиск по title идёт через `lower(data ->> 'title') LIKE %...%`
 * (без учёта регистра), это обслуживает GIN-триграммный индекс.
 *
 * Обогащение данными RequestedAction (актуальный статус, решение кворума,
 * сторона получателя) сюда не входит — его делает декоратор
 * `RequestedActionNotificationsQuery`.
 */
final class EloquentNotificationsQuery implements NotificationsQueryInterface
{
    public function paginate(NotificationListFilter $filter, int $page, int $perPage): PaginatedResult
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $query = $this->build($filter);

        // Эквивалент simplePaginate: без COUNT(*), берём perPage+1 строку,
        // чтобы узнать, есть ли следующая страница.
        $rows = $query->forPage($page, $perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;

        $items = $rows
            ->take($perPage)
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'type' => (string) $row->type,
                'data' => self::decodeData($row->data),
                'created_at' => (string) $row->created_at,
                'read_at' => $row->read_at === null ? null : (string) $row->read_at,
            ])
            ->values()
            ->all();

        $seen = ($page - 1) * $perPage + count($items);

        return new PaginatedResult(
            items: $items,
            total: $seen + ($hasMore ? 1 : 0),
            page: $page,
            perPage: $perPage,
        );
    }

    private function build(NotificationListFilter $filter): Builder
    {
        // Морфные колонки: notifiable_type = класс модели, notifiable_id = id.
        $query = DB::table('notifications')
            ->select(['id', 'type', 'data', 'created_at', 'read_at'])
            ->where('notifiable_type', $filter->notifiableType)
            ->where('notifiable_id', $filter->notifiableId);

        if ($filter->search !== null) {
            $like = self::escapeLike(mb_strtolower($filter->search));

            // Выражение совпадает с GIN(lower(data ->> 'title') gin_trgm_ops)
            // из миграции optimize_notifications_search.
            $query->whereRaw(
                "lower(data ->> 'title') like ?",
                ["%{$like}%"],
            );
        }

        if ($filter->onlyUnread !== null) {
            $filter->onlyUnread
                ? $query->whereNull('read_at')
                : $query->whereNotNull('read_at');
        }

        return $query->orderByDesc('created_at');
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeData(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Экранирование спецсимволов LIKE (\, %, _), чтобы «%» в поиске
     * не превращался в шаблон «любая подстрока».
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
