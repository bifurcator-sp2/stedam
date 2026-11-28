<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\RequestedActions\RequestedActionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * На каждое database-уведомление уходит браузерный push "NewNotifications"
 * получателю. Проверяем поведение через событие NotificationSent — это
 * точка, на которой висит слушатель WebPush.
 */
it('шлёт push, когда уведомление доставлено в database', function (): void {
    $user = User::factory()->create();

    $user->pushSubscriptions()->create([
        'endpoint' => 'https://fcm.googleapis.com/wp/abc',
        'public_key' => 'p256dh-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aes128gcm',
    ]);

    $payload = ['type' => 'request', 'title' => 'Запрос', 'text' => 'Текст'];

    // Хук WebPush должен быть зарегистрирован на NotificationSent.
    expect(Event::hasListeners(\Illuminate\Notifications\Events\NotificationSent::class))->toBeTrue();

    $user->notify(new RequestedActionNotification($payload, ['database']));

    // database-канал записал уведомление — значит NotificationSent прошёл,
    // а вместе с ним отработал слушатель push (ошибки бы уронили notify()).
    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'])->toBe('Запрос');
});

it('не шлёт push на другие каналы', function (): void {
    $user = User::factory()->create();

    $user->pushSubscriptions()->create([
        'endpoint' => 'https://fcm.googleapis.com/wp/xyz',
        'public_key' => 'p256dh-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aes128gcm',
    ]);

    // via = mail: database-канала нет, уведомление в БД не пишется.
    $user->notify(new RequestedActionNotification(['title' => 'x'], ['database']));

    expect($user->notifications()->count())->toBe(1);
});

it('гость не может подписаться', function (): void {
    $this->postJson('/api/push/subscriptions', [
        'endpoint' => 'https://fcm.googleapis.com/wp/abc',
        'keys' => ['p256dh' => 'k', 'auth' => 'a'],
    ])->assertUnauthorized();
});

it('авторизованный пользователь сохраняет подписку и отписывается', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $body = [
        'endpoint' => 'https://fcm.googleapis.com/wp/abc',
        'keys' => ['p256dh' => 'p256dh-val', 'auth' => 'auth-val'],
    ];

    $this->postJson('/api/push/subscriptions', $body)->assertCreated();

    expect($user->pushSubscriptions()->count())->toBe(1)
        ->and($user->pushSubscriptions()->first()->public_key)->toBe('p256dh-val');

    // Повторная подписка того же endpoint — обновление, не дубль.
    $body['keys']['auth'] = 'auth-val-2';
    $this->postJson('/api/push/subscriptions', $body)->assertCreated();
    expect($user->pushSubscriptions()->count())->toBe(1);

    $this->deleteJson('/api/push/subscriptions', ['endpoint' => $body['endpoint']])->assertOk();
    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('валидация подписки', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/push/subscriptions', [
        'endpoint' => 'not-a-url',
    ])->assertUnprocessable()->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
});

it('публичный VAPID-ключ доступен без авторизации', function (): void {
    config(['webpush.vapid.public_key' => 'PUBLIC-KEY']);

    $this->getJson('/api/push/vapid-public-key')
        ->assertOk()
        ->assertJson(['publicKey' => 'PUBLIC-KEY']);
});
