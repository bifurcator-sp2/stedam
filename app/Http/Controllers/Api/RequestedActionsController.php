<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\RequestedActions\Actions\CreateRequestedActionAction;
use App\Application\RequestedActions\Actions\GetApprovalFormAction;
use App\Application\RequestedActions\Actions\GetRequestFormAction;
use App\Application\RequestedActions\Actions\RegisterApprovalAction;
use App\Domain\RequestedActions\Enums\ApprovalResult;
use App\Domain\RequestedActions\ValueObjects\UserId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * HTTP-слой контекста RequestedAction (раздел 1 «http слой», 2.13).
 *
 * Четыре маршрута:
 *  - GET  request-action/form    → getRequestForm;
 *  - POST request-action/request → registerUserRequest (создание агрегата);
 *  - GET  approve-action/form    → getApprovalForm;
 *  - POST approve-action/approve → registerApproval.
 *
 * Обязательный параметр `action` — alias обработчика (`StudentToTeacher`).
 * Инициатор/отвечающий берётся из `auth()`. Остальные параметры — плоские
 * ключи запроса, уходят в `arguments` handler'а и затем в payload.
 *
 * Контроллер — только валидация ввода и вызов Action. Доменные исключения
 * маппятся в `bootstrap/app.php` в формат `{message, code}`.
 */
final readonly class RequestedActionsController
{
    private const RULES = [
        'action' => ['required', 'string', 'max:64'],
    ];

    /**
     * GET request-action/form — описание формы запроса на действие.
     *
     * Параметры (query-строка):
     *  - `action`    — обязательный alias обработчика (`StudentToTeacher`).
     *  - domain-параметры handler'а — любые плоские ключи; для
     *    `StudentToTeacher` обязателен `teacher_id` (int > 0), иначе 422.
     *    Инициатор (`user_id`) в аргументы подставляется из `auth()`,
     *    значение из запроса игнорируется.
     *
     * Ответ: `{title, text, approve}` — описание полей формы.
     */
    public function form(Request $request, GetRequestFormAction $action): JsonResponse
    {
        $validated = $request->validate(self::RULES);
        $arguments = $this->arguments($request, $validated);

        return response()->json(
            $action->execute($this->userId($request), $validated['action'], $arguments),
        );
    }

    /**
     * POST request-action/request — создаёт агрегат (запрос на действие).
     *
     * Параметры (тело запроса):
     *  - `action`    — обязательный alias обработчика (`StudentToTeacher`).
     *  - `approve`   — ответ пользователя на форму: `true` создаёт агрегат,
     *    `false`/отсутствие → 422 USER_REQUEST_DECLINED, агрегат не создаётся.
     *  - domain-параметры handler'а — любые плоские ключи; для
     *    `StudentToTeacher` обязателен `teacher_id` (int > 0).
     *    Инициатор (`user_id`) подставляется из `auth()`.
     *
     * Успех — 201 `{id, handler, status}`; повторный запрос идемпотентен
     * (возвращает тот же активный агрегат).
     */
    public function request(
        Request $request,
        CreateRequestedActionAction $action,
    ): JsonResponse {
        $validated = $request->validate(self::RULES);
        $arguments = $this->arguments($request, $validated);

        $created = $action->execute($this->userId($request), $arguments, $validated['action']);

        if ($created === null) {
            return response()->json([
                'message' => 'User declined the request',
                'code' => 'USER_REQUEST_DECLINED',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'id' => $created->id()?->value,
            'handler' => $created->handler(),
            'status' => $created->status()->value,
        ], Response::HTTP_CREATED);
    }

    /**
     * GET approve-action/form — описание формы принятия запроса.
     *
     * Параметры (query-строка):
     *  - `action` — обязательный alias обработчика (`StudentToTeacher`).
     *  - `id`     — обязательный id агрегата (int ≥ 1). Отсутствует или
     *    невалиден → 422 (правило валидации).
     *  - domain-параметры handler'а передаются в `arguments` как есть.
     *
     * Отвечающий берётся из `auth()`; не приёмщик → 403, закрытый запрос → 409.
     */
    public function approvalForm(Request $request, GetApprovalFormAction $action): JsonResponse
    {
        $validated = $request->validate(self::RULES + [
            'id' => ['required', 'integer', 'min:1'],
        ]);
        $arguments = $this->arguments($request, $validated);

        return response()->json($action->execute(
            (int) $validated['id'],
            $this->userId($request),
            $arguments,
        ));
    }

    /**
     * POST approve-action/approve — голос приёмщика по запросу.
     *
     * Параметры (тело запроса):
     *  - `action`  — обязательный alias обработчика (`StudentToTeacher`).
     *  - `id`      — обязательный id агрегата (int ≥ 1); невалиден → 422.
     *  - `approve` — необязательный boolean: `true` — голос «за», `false`
     *    или отсутствие — «против» (голос всё равно записывается).
     *
     * Отвечающий берётся из `auth()`: не приёмщик → 403, не найдено → 404,
     * повторный голос/закрытый запрос → 409. Ответ `{id, result, status}`,
     * где `result` — значение enum `ApprovalResult` (accepted/declined/
     * waiting/closed); `CLOSED` → 409 REQUESTED_ACTION_CLOSED.
     */
    public function approve(Request $request, RegisterApprovalAction $action): JsonResponse
    {
        $validated = $request->validate(self::RULES + [
            'id' => ['required', 'integer', 'min:1'],
            'approve' => ['nullable', 'boolean'],
        ]);
        $arguments = $this->arguments($request, $validated);

        $result = $action->execute(
            (int) $validated['id'],
            $this->userId($request),
            $arguments,
        );

        $aggregate = $action->find((int) $validated['id']);

        $payload = [
            'id' => $validated['id'],
            'result' => $result->value,
            'status' => $aggregate->status()->value,
        ];

        if ($result === ApprovalResult::CLOSED) {
            return response()->json($payload + [
                'message' => 'Requested action is closed',
                'code' => 'REQUESTED_ACTION_CLOSED',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json($payload);
    }

    // ------------------------------------------------------------------

    private function userId(Request $request): UserId
    {
        return new UserId((int) $request->user()->getAuthIdentifier());
    }

    /**
     * Плоские параметры запроса без служебных ключей — уходят в handler
     * как `arguments` и затем в payload.
     *
     * Берутся все входные данные (query + body), а не только `$validated`:
     * `validate()` возвращает лишь поля с объявленными правилами (`action`,
     * `id`, `approve`), а domain-специфичные ключи (`teacher_id` и т.п.)
     * правил не имеют и иначе терялись бы.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function arguments(Request $request, array $validated): array
    {
        $arguments = array_merge($request->all(), $validated);

        unset($arguments['action'], $arguments['id']);

        return $arguments;
    }
}
