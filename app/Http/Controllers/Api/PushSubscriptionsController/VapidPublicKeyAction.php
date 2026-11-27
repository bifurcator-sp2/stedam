<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\PushSubscriptionsController;

use Illuminate\Http\JsonResponse;

/**
 * Публичный ключ VAPID (base64url, без padding) для подписки в браузере.
 *
 * GET /api/push/vapid-public-key  → { "publicKey": "..." }
 *
 * Ключ публичный по определению — авторизация не нужна.
 */
class VapidPublicKeyAction
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'publicKey' => (string) config('webpush.vapid.public_key', ''),
        ]);
    }
}
