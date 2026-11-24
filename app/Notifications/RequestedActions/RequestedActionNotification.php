<?php

declare(strict_types=1);

namespace App\Notifications\RequestedActions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Базовая мультиканальная нотификация контекста RequestedAction (2.11).
 *
 * Один экземпляр = одно сообщение одному участнику. Содержимое готовит
 * handler через `getXxxNotificationMessage()` — нотификация не знает домена
 * и только передаёт `title`/`text` в выбранные каналы.
 *
 * Обязательный канал — `database` (2.15 п.12): сообщение сохраняется в
 * таблице `notifications` и отдаётся SPA через стандартный эндпоинт.
 */
final class RequestedActionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload  сообщение из handler'а
     * @param  list<string>  $via  каналы из handler->getNotificationChannels()
     */
    public function __construct(
        public readonly array $payload,
        public readonly array $via,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->via !== [] ? $this->via : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) ($this->payload['title'] ?? ''))
            ->line((string) ($this->payload['text'] ?? ''));
    }
}
