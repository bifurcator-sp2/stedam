<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\PushSubscriptionsController\DestroyPushSubscriptionAction;
use App\Http\Controllers\Api\PushSubscriptionsController\StorePushSubscriptionAction;
use App\Http\Controllers\Api\PushSubscriptionsController\VapidPublicKeyAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP-эндпоинты браузерных push-уведомлений (WebPush / VAPID).
 *
 * Контракт и фронтенд-часть — docs/browser-push-spec.md.
 */
class PushSubscriptionsController
{
    /**
     * Публичный VAPID-ключ для PushManager.subscribe() на фронте.
     *
     * GET /api/push/vapid-public-key  (без авторизации)
     */
    public function vapidPublicKey(VapidPublicKeyAction $action): JsonResponse
    {
        return $action();
    }

    /**
     * Сохранить/обновить подписку браузера.
     *
     * POST /api/push/subscriptions
     */
    public function store(Request $request, StorePushSubscriptionAction $action): JsonResponse
    {
        return $action($request);
    }

    /**
     * Удалить подписку браузера (отписка, logout).
     *
     * DELETE /api/push/subscriptions  { "endpoint": "..." }
     */
    public function destroy(Request $request, DestroyPushSubscriptionAction $action): JsonResponse
    {
        return $action($request);
    }
}
