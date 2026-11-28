<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\DTOs\NotificationListFilter;
use App\Application\Notifications\Ports\NotificationsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Enums\NotificationType;
use App\Enums\RecipientRole;
use Illuminate\Support\Facades\DB;

/**
 * Декоратор read-порта уведомлений: подмешивает в `data` уведомлений
 * RequestedAction актуальное состояние агрегата.
 *
 * Базовый query отдаёт «сырые» уведомления из `notifications`. Здесь для
 * страницы собираются `requested_action_id`, одним запросом читаются
 * актуальные `status`, `decision`, `user_id`, `payload` и накладываются в
 * `data`:
 *   — `status`   — текущий статус заявки (а не снимок на момент отправки);
 *   — `decision` — итог кворума (`accepted`/`declined`), если есть;
 *   — `recipient_role` — сторона получателя: берётся из `data.recipient_role`
 *     (записан handler'ом при отправке, 1C), fallback — вычисляется по
 *     `user_id`/`payload.consumers` относительно получателя списка.
 *
 * Прочие классы уведомлений и «сиротские» (запись удалена retention'ом)
 * не меняются.
 */
final readonly class RequestedActionNotificationsQuery implements NotificationsQueryInterface
{
    public function __construct(
        private NotificationsQueryInterface $notifications,
    ) {}

    public function paginate(NotificationListFilter $filter, int $page, int $perPage): PaginatedResult
    {
        $result = $this->notifications->paginate($filter, $page, $perPage);

        if ($result->items === []) {
            return $result;
        }

        $actionIds = self::requestedActionIds($result->items);

        if ($actionIds === []) {
            return $result;
        }

        $actions = $this->loadActions($actionIds);

        if ($actions === []) {
            return $result;
        }

        $items = array_map(
            static fn (array $item): array => self::enrich($item, $actions, $filter->notifiableId),
            $result->items,
        );

        return new PaginatedResult(
            items: $items,
            total: $result->total,
            page: $result->page,
            perPage: $result->perPage,
        );
    }

    /**
     * Уникальные `requested_action_id` уведомлений RequestedAction на странице.
     *
     * @param  list<array{id: string, type: string, data: array<string, mixed>}>  $items
     * @return list<int>
     */
    private static function requestedActionIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (NotificationType::fromClass($item['type']) !== NotificationType::RequestedAction) {
                continue;
            }

            $id = $item['data']['requested_action_id'] ?? null;

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Актуальное состояние заявок одним запросом (без N+1).
     *
     * Заявка создаётся раньше своего уведомления, а retention удаляет только
     * завершённые ветки целиком, поэтому «свежая» запись под id ровно одна;
     * `orderByDesc('created_at')` + первый по id страхует от редкого дубля id
     * на стыке партиций.
     *
     * @param  list<int>  $actionIds
     * @return array<int, object{id: int|string, status: string, decision: string|null, user_id: int|string, payload: mixed}>
     */
    private function loadActions(array $actionIds): array
    {
        $rows = DB::table('requested_actions')
            ->select(['id', 'status', 'decision', 'user_id', 'payload'])
            ->whereIn('id', $actionIds)
            ->orderByDesc('created_at')
            ->get();

        $actions = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;

            if (! isset($actions[$id])) {
                $actions[$id] = $row;
            }
        }

        return $actions;
    }

    /**
     * Накладывает актуальное состояние заявки на `data` уведомления.
     *
     * @param  array{id: string, type: string, data: array<string, mixed>}  $item
     * @param  array<int, object>  $actions
     * @return array{id: string, type: string, data: array<string, mixed>}
     */
    private static function enrich(array $item, array $actions, int $notifiableId): array
    {
        if (NotificationType::fromClass($item['type']) !== NotificationType::RequestedAction) {
            return $item;
        }

        $rawId = $item['data']['requested_action_id'] ?? null;
        $actionId = is_int($rawId) || (is_string($rawId) && ctype_digit($rawId)) ? (int) $rawId : null;

        if ($actionId === null || ! isset($actions[$actionId])) {
            return $item;
        }

        $action = $actions[$actionId];

        if ($action->status !== null && $action->status !== '') {
            $item['data']['status'] = (string) $action->status;
        }

        if ($action->decision !== null && $action->decision !== '') {
            $item['data']['decision'] = (string) $action->decision;
        }

        // Роль записана при отправке (1C); fallback — вывод по данным агрегата.
        $item['data']['recipient_role'] = (string) (
            $item['data']['recipient_role']
            ?? self::deriveRecipientRole($action, $notifiableId)->value
        );

        return $item;
    }

    /**
     * Fallback-вывод стороны получателя по данным заявки: инициатор —
     * `requested_actions.user_id`, одобряющие — `payload.consumers`.
     */
    private static function deriveRecipientRole(object $action, int $notifiableId): RecipientRole
    {
        if ((int) $action->user_id === $notifiableId) {
            return RecipientRole::Initiator;
        }

        $payload = $action->payload;

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $consumers = is_array($payload) ? ($payload['consumers'] ?? []) : [];

        if (is_array($consumers)) {
            foreach ($consumers as $consumerId) {
                if ((int) $consumerId === $notifiableId) {
                    return RecipientRole::Consumer;
                }
            }
        }

        return RecipientRole::Consumer;
    }
}
