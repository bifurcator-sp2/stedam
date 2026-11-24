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
     * GET request-action/form?action=StudentToTeacher&...
     */
    public function form(Request $request, GetRequestFormAction $action): JsonResponse
    {
        $validated = $request->validate(self::RULES);
        $arguments = $this->arguments($validated);

        return response()->json(
            $action->execute($this->userId($request), $validated['action'], $arguments),
        );
    }

    /**
     * POST request-action/request — создаёт агрегат.
     *
     * `registerUserRequest=false` → 422 USER_REQUEST_DECLINED, агрегат не
     * создаётся. Успех — 201 с краткой карточкой агрегата.
     */
    public function request(
        Request $request,
        CreateRequestedActionAction $action,
    ): JsonResponse {
        $validated = $request->validate(self::RULES);
        $arguments = $this->arguments($validated);

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
     * GET approve-action/form?action=StudentToTeacher&id=123
     *
     * `id` обязателен (п.8). Невалидный `id` → 422 (правило валидации).
     */
    public function approvalForm(Request $request, GetApprovalFormAction $action): JsonResponse
    {
        $validated = $request->validate(self::RULES + [
            'id' => ['required', 'integer', 'min:1'],
        ]);
        $arguments = $this->arguments($validated);

        return response()->json($action->execute(
            (int) $validated['id'],
            $this->userId($request),
            $arguments,
        ));
    }

    /**
     * POST approve-action/approve?action=StudentToTeacher&id=123
     *
     * Тело запроса — `id` и (опционально) `approve`. Итог отдаётся как
     * `{id, result, status}`; `CLOSED` → 409 (п.10), реализуется маппингом
     * или явной проверкой ниже.
     */
    public function approve(Request $request, RegisterApprovalAction $action): JsonResponse
    {
        $validated = $request->validate(self::RULES + [
            'id' => ['required', 'integer', 'min:1'],
            'approve' => ['nullable', 'boolean'],
        ]);
        $arguments = $this->arguments($validated);

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
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function arguments(array $validated): array
    {
        unset($validated['action']);

        return $validated;
    }
}
