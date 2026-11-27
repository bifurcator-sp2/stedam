<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Генерация пары VAPID-ключей (P-256 / ES256) для WebPush.
 *
 *   php artisan webpush:vapid
 *
 * Печатает готовые строки для .env (+ APP_URL в `subject`). Ключи
 * долговременные: одна пара на всё приложение, менять только осознанно
 * (после смены все старые push-подписки станут невалидными и их надо
 * будет переоформить на клиентах).
 */
final class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'webpush:vapid {--subject= : mailto: или https:// в поле sub (по умолчанию APP_URL)}';

    protected $description = 'Сгенерировать пару VAPID-ключей для браузерных push-уведомлений';

    public function handle(): int
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            $this->error('Не удалось сгенерировать EC-ключ.');

            return self::FAILURE;
        }

        $details = openssl_pkey_get_details($key);
        $publicPoint = "\x04".$details['ec']['x'].$details['ec']['y'];
        $private = $details['ec']['d'];

        $subject = (string) ($this->option('subject') ?? config('app.url', 'mailto:admin@example.com'));

        $this->line('PUSH_VAPID_PUBLIC_KEY='.self::b64url($publicPoint));
        $this->line('PUSH_VAPID_PRIVATE_KEY='.self::b64url($private));
        $this->line('PUSH_VAPID_SUBJECT='.$subject);
        $this->newLine();
        $this->comment('Скопируйте строки в .env и выполните: php artisan config:clear');

        return self::SUCCESS;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
