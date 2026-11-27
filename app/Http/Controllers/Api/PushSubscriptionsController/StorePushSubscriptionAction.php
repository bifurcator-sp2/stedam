<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\PushSubscriptionsController;

use App\Http\Controllers\Api\PushSubscriptionsController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Сохранение push-подписки браузера.
 *
 * POST /api/push/subscriptions
 * {
 *   "endpoint": "https://fcm.googleapis.com/...",
 *   "keys": { "p256dh": "...", "auth": "..." },
 *   "content_encoding": "aes128gcm"   // необязательно, по умолчанию aes128gcm
 * }
 *
 * Подписка уникальна по `endpoint` (одно устройство/браузер = одна строка),
 * повторный POST обновляет её и привязывает к текущему пользователю.
 * Аналитика по `user_agent` запроса сохраняется.
 */
class StorePushSubscriptionAction
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ]);

        $user = $request->user();

        $user->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['content_encoding'] ?? 'aes128gcm',
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json(['message' => 'Subscribed'], 201);
    }
}
