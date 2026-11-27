<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries;

use App\Application\Notifications\DTOs\NotificationListFilter;
use App\Application\Notifications\Ports\NotificationsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Enums\NotificationType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Чтение стандартных (database) уведомлений Laravel на PostgreSQL.
 *
 * Источник — таблица `notifications` (канал `database`). Колонка `data` —
 * jsonb, поиск по title идёт через `lower(data ->> 'title') LIKE %...%`
 * (без учёта регистра), это обслуживает GIN-триграммный индекс.
 *
 * Для уведомлений RequestedAction (`data.requested_action_id`) в `data.status`
 * подмешивается АКТУАЛЬНЫЙ статус записи из `requested_actions`: уведомления
 * иммутабельны и хранят статус на момент отправки, а SPA нужно текущее состояние.
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
                'data' => self::withActualStatus(
                    (string) $row->type,
                    self::decodeData($row->data),
                    $row->requested_action_status ?? null,
                    $row->requested_action_decision ?? null,
                ),
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
        //
        // Актуальный статус заявки. Заявка создаётся раньше любого своего
        // уведомления, а retention (prune/retain) удаляет только завершённые
        // ветки целиком — «свежая» запись под requested_action_id ровно одна.
        // Коррелированный подзапрос «id + LIMIT 1» попадает в индекс
        // партиционированного родителя без Seq Scan.
        $query = DB::table('notifications')
            ->select(['id', 'type', 'data'])
            ->selectSub(
                $this->requestedActionSub('status'),
                'requested_action_status',
            )
            ->selectSub(
                $this->requestedActionSub('decision'),
                'requested_action_decision',
            )
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

        return $query->orderByDesc('created_at');
    }

    /**
     * Коррелированный подзапрос конкретной колонки `requested_actions` по
     * `data.requested_action_id` уведомления. «id + LIMIT 1» попадает в индекс
     * партиционированного родителя без Seq Scan.
     *
     * @param  'status'|'decision'  $column
     */
    private function requestedActionSub(string $column): Builder
    {
        return DB::table('requested_actions')
            ->select($column)
            ->whereRaw(
                "requested_actions.id = (case when jsonb_typeof(notifications.data -> 'requested_action_id') = 'number'"
                ." then (notifications.data ->> 'requested_action_id')::bigint end)",
            )
            ->orderBy('requested_actions.created_at')
            ->limit(1);
    }

    /**
     * Подменяет `data.status` и `data.decision` уведомлений RequestedAction
     * актуальными значениями из `requested_actions`. Для прочих типов
     * уведомлений `data` не меняется; для «сиротских» (запись ещё не
     * создана/уже удалена) — без изменений.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withActualStatus(string $fqcn, array $data, mixed $status, mixed $decision): array
    {
        if (NotificationType::fromClass($fqcn) !== NotificationType::RequestedAction) {
            return $data;
        }

        if ($status !== null && $status !== '') {
            $data['status'] = (string) $status;
        }

        if ($decision !== null && $decision !== '') {
            $data['decision'] = (string) $decision;
        }

        return $data;
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
