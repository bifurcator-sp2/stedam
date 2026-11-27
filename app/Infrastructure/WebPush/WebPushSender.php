<?php

declare(strict_types=1);

namespace App\Infrastructure\WebPush;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;

/**
 * Рассылка push-сообщения по ВСЕМ подпискам получателя-пользователя.
 *
 * Политика сбоев:
 *  - подписка «мертва» (404/410) — удаляется из БД;
 *  - прочие ошибки — логируются, подписка остаётся;
 *  - исключения не пробрасываются: уведомление уже сохранено в database,
 *    падение push не должно откатывать/ломать доставку других каналов.
 */
final readonly class WebPushSender
{
    public function __construct(
        private WebPushTransportInterface $transport,
        private Repository $config,
    ) {}

    /**
     * @param  iterable<\App\Models\PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     */
    public function sendToSubscriptions(iterable $subscriptions, array $payload): void
    {
        if (! (bool) $this->config->get('webpush.enabled', true)) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            $result = $this->transport->send([
                'endpoint' => $subscription->endpoint,
                'p256dh' => $subscription->public_key,
                'auth' => $subscription->auth_token,
            ], $payload);

            if ($result->gone) {
                $subscription->delete();

                continue;
            }

            if (! $result->ok) {
                Log::warning('WebPush send failed', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'status' => $result->statusCode,
                    'error' => $result->error,
                ]);
            }
        }
    }
}
