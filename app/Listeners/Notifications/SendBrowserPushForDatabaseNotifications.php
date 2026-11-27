<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Infrastructure\WebPush\WebPushSender;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Дублирует браузерным push каждое уведомление, доставленное получателю в
 * канал `database`.
 *
 * Слушает framework-событие `NotificationSent` — оно диспатчится ПОСЛЕ
 * успешной доставки в каждый канал, отдельными событиями. Поэтому:
 *  - работает для ЛЮБОГО класса Notification, у которого в `via()` есть
 *    `database`, не только для RequestedAction;
 *  - к моменту push запись в `notifications` уже создана (клик по push
 *    открывает реально существующее уведомление);
 *  - реагируем только на `$event->channel === 'database'`, иначе на
 *    мультиканальной нотификации отправили бы push дважды/трижды.
 *
 * Важно: `NotificationSent` для должно быть зарегистрировано синхронно
 * (без ShouldQueue), иначе порядок каналов не гарантирован.
 * Сами push-отправки внутри канала не блокируют HTTP-ответ только если
 * оригинальная Notification реализует ShouldQueue — она целиком ставится в
 * очередь вместе с database-каналом (стандартное поведение Laravel).
 */
final readonly class SendBrowserPushForDatabaseNotifications
{
    public function __construct(private WebPushSender $sender) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        $user = $event->notifiable;

        // Пушаем только реально существующим моделям-получателям.
        if (! $user instanceof User) {
            return;
        }

        $subscriptions = $user->pushSubscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        // for a database channel $event->response — массив из toDatabase/toArray.
        $data = is_array($event->response) ? $event->response : [];

        $this->sender->sendToSubscriptions($subscriptions, [
            'type' => 'NewNotifications',
            'title' => 'NewNotifications',
            'body' => 'NewNotifications',
            'url' => $this->resolveUrl($data),
            'data' => $data,
        ]);
    }

    /**
     * Адрес клика по push. По умолчанию — раздел уведомлений; если в данных
     * есть `approve_url` (requested actions), ведём сразу на форму действия.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveUrl(array $data): string
    {
        if (isset($data['approve_url']) && is_string($data['approve_url'])) {
            return '/app/'.$data['approve_url'];
        }

        return (string) config('webpush.defaults.url', '/app/notifications');
    }
}
