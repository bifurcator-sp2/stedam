<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\WebPush\Aes128GcmWebPushEncoder;
use App\Infrastructure\WebPush\GuzzleWebPushTransport;
use App\Infrastructure\WebPush\WebPushTransportInterface;
use App\Listeners\Notifications\SendBrowserPushForDatabaseNotifications;
use GuzzleHttp\Client;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Браузерные push-уведомления (WebPush / VAPID). См. docs/browser-push-spec.md.
 *
 *  - транспорт ({@see WebPushTransportInterface}) собирается из VAPID-ключей
 *    конфига `config/webpush.php`;
 *  - каждое уведомление, доставленное в канал `database`, дублируется push'ем
 *    получателю через слушатель {@see SendBrowserPushForDatabaseNotifications}.
 *
 * Ключи пустые → транспорт всё равно биндится (чтобы приложение/тесты
 * стартовали), а реальные отправки падают с ошибкой и логируются
 * в {@see \App\Infrastructure\WebPush\WebPushSender}.
 */
final class WebPushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WebPushTransportInterface::class, function (): WebPushTransportInterface {
            return new GuzzleWebPushTransport(
                http: new Client(['timeout' => 10, 'http_errors' => false]),
                encoder: new Aes128GcmWebPushEncoder(
                    vapidPublicKeyRaw: self::b64urlDecode((string) config('webpush.vapid.public_key')),
                    vapidPrivateKeyRaw: self::b64urlDecode((string) config('webpush.vapid.private_key')),
                    vapidSubject: (string) config('webpush.vapid.subject', 'mailto:admin@example.com'),
                ),
                ttl: (int) config('webpush.ttl', 86400),
                urgency: (string) config('webpush.urgency', 'normal'),
            );
        });
    }

    public function boot(): void
    {
        // Push на каждое database-уведомление. Слушатель синхронный — должен
        // выполниться сразу после записи в `notifications`. Если исходная
        // Notification implements ShouldQueue, вся доставка (включая этот
        // хук) происходит в воркере, а не в HTTP-ответе.
        Event::listen(NotificationSent::class, SendBrowserPushForDatabaseNotifications::class);
    }

    private static function b64urlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), (int) (ceil(strlen($data) / 4) * 4), '=');

        return (string) base64_decode($padded, true);
    }
}
