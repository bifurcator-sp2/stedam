<?php

declare(strict_types=1);

use App\Infrastructure\WebPush\WebPushResult;
use App\Infrastructure\WebPush\WebPushSender;
use App\Infrastructure\WebPush\WebPushTransportInterface;
use App\Listeners\Notifications\SendBrowserPushForDatabaseNotifications;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\RequestedActions\RequestedActionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Фейковый транспорт: запоминает вызовы, отвечает заданными результатами.
 */
final class RecordingWebPushTransport implements WebPushTransportInterface
{
    /** @var list<array{subscription: array<string, mixed>, payload: array<string, mixed>}> */
    public array $sent = [];

    /** @var list<WebPushResult> */
    public array $results = [];

    public function send(array $subscription, array $payload): WebPushResult
    {
        $this->sent[] = ['subscription' => $subscription, 'payload' => $payload];

        return $this->results === [] ? WebPushResult::success(201) : array_shift($this->results);
    }
}

function makeSubscription(User $user, string $endpoint): PushSubscription
{
    return $user->pushSubscriptions()->create([
        'endpoint' => $endpoint,
        'public_key' => 'p256dh',
        'auth_token' => 'auth',
        'content_encoding' => 'aes128gcm',
    ]);
}

it('listener отправляет push "NewNotifications" на database-канал', function (): void {
    // Под `artisan test` приложение бут'ится дважды (консольное ядро, затем
    // TestCase::createApplication), поэтому провайдер мог зарегистрировать
    // слушатель дважды в общем диспетчере. Идемпотентно регистрируем свой.
    app('events')->forget(NotificationSent::class);
    Event::listen(NotificationSent::class, SendBrowserPushForDatabaseNotifications::class);

    $user = User::factory()->create();
    makeSubscription($user, 'https://fcm.googleapis.com/wp/1');

    // Слушатель резолвит WebPushSender из контейнера, а тот — транспорт.
    // Подмена транспорта не даёт хуку ходить в сеть.
    $transport = new RecordingWebPushTransport();
    $this->app->instance(WebPushTransportInterface::class, $transport);

    event(new NotificationSent(
        $user,
        new RequestedActionNotification(['title' => 'T'], ['database']),
        'database',
        ['type' => 'request', 'title' => 'T', 'approve_url' => 'approve-action/form?action=X&id=1'],
    ));

    expect($transport->sent)->toHaveCount(1);
    $payload = $transport->sent[0]['payload'];

    expect($payload['title'])->toBe('NewNotifications')
        ->and($payload['body'])->toBe('NewNotifications')
        ->and($payload['type'])->toBe('NewNotifications')
        ->and($payload['url'])->toBe('/app/approve-action/form?action=X&id=1');
});

it('listener игнорирует не-database каналы', function (): void {
    $user = User::factory()->create();
    makeSubscription($user, 'https://fcm.googleapis.com/wp/1');

    $transport = new RecordingWebPushTransport();
    $this->app->instance(WebPushTransportInterface::class, $transport);

    event(new NotificationSent(
        $user,
        new RequestedActionNotification(['title' => 'T'], ['mail']),
        'mail',
        null,
    ));

    expect($transport->sent)->toBeEmpty();
});

it('подписка удаляется, если push-сервис вернул 410', function (): void {
    $user = User::factory()->create();
    $sub = makeSubscription($user, 'https://fcm.googleapis.com/wp/gone');

    $transport = new RecordingWebPushTransport();
    $transport->results = [WebPushResult::gone(410)];

    $sender = new WebPushSender($transport, app('config'));
    $sender->sendToSubscriptions($user->pushSubscriptions()->get(), ['title' => 'NewNotifications']);

    expect(PushSubscription::query()->find($sub->id))->toBeNull();
});
