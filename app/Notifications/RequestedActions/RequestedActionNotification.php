<?php

declare(strict_types=1);

namespace App\Notifications\RequestedActions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
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
 *
 * `ShouldQueueAfterCommit`, а не `ShouldQueue`: `notify()` вызывается внутри
 * транзакции воркера (messaging ставит pending, handle-approved пишет done).
 * С `ShouldQueue` задача попадала в очередь до коммита, воркер мог взять её
 * раньше, чем транзакция завершится, — уведомление уходило до фактической
 * фиксации перехода. `ShouldQueueAfterCommit` гарантирует постановку в очередь
 * только после успешного коммита.
 *
 * Примечание: дубли уведомлений из дампа были вызваны не этим, а двойной
 * регистрацией слушателей (автопоиск app/Listeners + ручной Event::listen);
 * см. bootstrap/app.php -> withEvents(discover: false).
 */
final class RequestedActionNotification extends Notification implements ShouldQueueAfterCommit
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
