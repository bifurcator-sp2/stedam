<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\PushSubscriptionsController;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Удаление push-подписки текущего пользователя по endpoint.
 *
 * DELETE /api/push/subscriptions  { "endpoint": "..." }
 *
 * Идемпотентно: несуществующая подписка — тоже 200.
 */
class DestroyPushSubscriptionAction
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        $request->user()
            ->pushSubscriptions()
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return response()->json(['message' => 'Unsubscribed']);
    }
}
