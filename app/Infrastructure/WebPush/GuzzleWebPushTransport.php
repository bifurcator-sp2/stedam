<?php

declare(strict_types=1);

namespace App\Infrastructure\WebPush;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
/**
 * Реальный транспорт WebPush через Guzzle.
 *
 * Шифрование/подпись — {@see Aes128GcmWebPushEncoder}, здесь только HTTP.
 * Ответы 404/410 от push-сервиса означают, что подписка больше не валидна
 * (браузер её отозвал) — отдаём result->gone, чтобы вызывающий удалил её.
 */
final readonly class GuzzleWebPushTransport implements WebPushTransportInterface
{
    public function __construct(
        private ClientInterface $http,
        private Aes128GcmWebPushEncoder $encoder,
        private int $ttl,
        private string $urgency,
    ) {}

    public function send(array $subscription, array $payload): WebPushResult
    {
        try {
            $encoded = $this->encoder->encode(
                [
                    'endpoint' => (string) $subscription['endpoint'],
                    'p256dh' => (string) $subscription['p256dh'],
                    'auth' => (string) $subscription['auth'],
                ],
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $this->ttl,
                $this->urgency,
            );
        } catch (\Throwable $e) {
            return WebPushResult::failed(0, $e->getMessage());
        }

        $request = new Request('POST', (string) $subscription['endpoint'], $encoded['headers'], $encoded['body']);

        try {
            $response = $this->http->send($request);
        } catch (GuzzleException $e) {
            // Конфиг без http_errors=false бросит RequestException на 4xx/5xx —
            // ответ у него внутри, поэтому подписка всё равно определяется.
            $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;

            if ($response === null) {
                return WebPushResult::failed(0, $e->getMessage());
            }
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return WebPushResult::success($status);
        }

        if ($status === 404 || $status === 410) {
            return WebPushResult::gone($status);
        }

        return WebPushResult::failed($status, 'unexpected status');
    }
}
