<?php

namespace App\Http\Controllers\Api;

use App\Application\Notifications\Actions\ListNotificationsAction;
use App\Enums\ActionNotificationType;
use App\Enums\NotificationType;
use App\Http\Resources\NotificationListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationsController
{
    /**
     * Список уведомлений текущего пользователя: поиск по data.title + пагинация.
     *
     * GET /notifications?search=<...>&page=1&per_page=20
     *
     *   — получатель ограничен моделью текущего пользователя;
     *   — search: вхождения (без учёта регистра) в data.title.
     *
     * Ответ:
     *   data[]       — элементы; в каждом `type` — КОРОТКОЕ имя типа
     *                  уведомления (кейс `NotificationType`, напр.
     *                  `RequestedAction`), а не внутренний FQCN;
     *                  `data.status` у RequestedAction — АКТУАЛЬНЫЙ статус
     *                  записи из `requested_actions` (не снимок на момент
     *                  отправки уведомления), `data.decision` — итоговое
     *                  решение кворума (`accepted`/`declined`, `null`);
     *   types        — справочник «короткое имя класса → локализованное
     *                  название» (реестр NotificationType), плюс «заглушки»
     *                  для встреченных, но не зарегистрированных классов;
     *   action_types — справочник по `data.type` (смысл сообщения:
     *                  request/approved/…), старый `types`.
     *
     * Внимание: `type` в элементе описывает КЛАСС уведомления, а
     * `data.type` — его семантику (ActionNotificationType). Это разные оси.
     */
    public function index(Request $request, ListNotificationsAction $listNotifications): JsonResponse
    {
        $user = $request->user();

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $result = $listNotifications->paginate(
            notifiableType: $user->getMorphClass(),
            notifiableId: (int) $user->getKey(),
            input: $request->query(),
            page: $page,
            perPage: $perPage,
        );

        // Нормализуем FQCN из БД в короткие имена + собираем справочник
        // покрывающий все встреченные на странице типы.
        $fqcns = array_map(
            static fn (array $item): ?string => $item['type'] ?? null,
            $result->items,
        );
        $items = array_map(
            static fn (array $item): array => [
                'id' => $item['id'],
                'type' => NotificationType::shortNameOf($item['type'] ?? null),
                'data' => $item['data'],
            ],
            $result->items,
        );

        return response()->json([
            'data' => NotificationListResource::collection(collect($items))->resolve($request),
            'meta' => [
                'current_page' => $result->page,
                'per_page' => $result->perPage,
                'has_more_pages' => $result->hasMorePages(),
                'from' => $result->items === [] ? null : ($result->page - 1) * $result->perPage + 1,
                'to' => $result->items === [] ? null : ($result->page - 1) * $result->perPage + count($result->items),
            ],
            // Справочник типов-классов уведомлений (короткое имя → название).
            'types' => NotificationType::labelsCovering($fqcns),
            // Справочник по data.type (смысл сообщения) — старый `types`.
            'action_types' => ActionNotificationType::labels(),
        ]);
    }
}
