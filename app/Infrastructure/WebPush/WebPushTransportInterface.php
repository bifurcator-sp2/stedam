<?php

declare(strict_types=1);

namespace App\Infrastructure\WebPush;

/**
 * Транспорт одного WebPush-сообщения (без Laravel-зависимостей от
 * Notification). Канал {@see \App\Notifications\Channels\WebPushChannel}
 * знает, как собрать payload из Notification, а этот интерфейс — как его
 * доставить.
 */
interface WebPushTransportInterface
{
    /**
     * Отправить произвольный массив-сообщение на одну подписку.
     *
     * @param  array<string, mixed>  $subscription  { endpoint, p256dh, auth, content_encoding }
     * @param  array<string, mixed>  $payload       тело push (будет JSON-сериализовано)
     *
     * @return WebPushResult
     */
    public function send(array $subscription, array $payload): WebPushResult;
}
