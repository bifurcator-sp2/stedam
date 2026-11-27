<?php

declare(strict_types=1);

namespace App\Infrastructure\WebPush;

/**
 * Результат одной отправки push. Отличаем «подписка мертва» (браузер
 * отписался, 404/410 от push-сервиса) от прочих ошибок: первые удаляются
 * из БД, вторые — только логируются.
 */
final readonly class WebPushResult
{
    public function __construct(
        public bool $ok,
        public bool $gone = false,
        public ?int $statusCode = null,
        public ?string $error = null,
    ) {}

    public static function success(int $status): self
    {
        return new self(ok: true, statusCode: $status);
    }

    public static function gone(int $status): self
    {
        return new self(ok: false, gone: true, statusCode: $status);
    }

    public static function failed(int $status, string $error): self
    {
        return new self(ok: false, statusCode: $status, error: $error);
    }
}
