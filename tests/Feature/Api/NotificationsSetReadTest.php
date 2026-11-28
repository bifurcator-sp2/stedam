<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\RequestedActions\RequestedActionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Записать в БД database-уведомление получателю.
 *
 * @return string id уведомления
 */
function storeUnreadNotification(User $user, ?string $readAt = null): string
{
    $id = (string) Str::uuid();

    $user->notifications()->create([
        'id' => $id,
        'type' => RequestedActionNotification::class,
        'data' => ['type' => 'request', 'title' => 'Запрос'],
        'read_at' => $readAt,
    ]);

    return $id;
}

function readAtOf(string $notificationId): mixed
{
    return DB::table('notifications')->where('id', $notificationId)->value('read_at');
}

// ---------------------------------------------------------------------
// Доступ
// ---------------------------------------------------------------------

it('гость не может пометить уведомления прочитанными', function () {
    $this->patchJson('/api/notifications', ['notifications_ids' => [(string) Str::uuid()]])
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------
// Проставление read_at
// ---------------------------------------------------------------------

it('помечает прочитанными свои уведомления и возвращает число обновлённых', function () {
    $me = User::factory()->create();
    $first = storeUnreadNotification($me);
    $second = storeUnreadNotification($me);

    Sanctum::actingAs($me);

    $this->patchJson('/api/notifications', ['notifications_ids' => [$first, $second]])
        ->assertOk()
        ->assertJson(['updated' => 2]);

    expect(readAtOf($first))->not->toBeNull()
        ->and(readAtOf($second))->not->toBeNull();
});

it('после прочтения уведомление отдаётся со статусом read', function () {
    $me = User::factory()->create();
    $id = storeUnreadNotification($me);

    Sanctum::actingAs($me);

    $this->patchJson('/api/notifications', ['notifications_ids' => [$id]])->assertOk();

    expect($this->getJson('/api/notifications?status=unread')->assertOk()->json('data'))
        ->toHaveCount(0);

    $read = $this->getJson('/api/notifications?status=read')->assertOk()->json('data');

    expect($read)->toHaveCount(1)
        ->and($read[0]['id'])->toBe($id)
        ->and($read[0]['read'])->toBeTrue();
});

it('не помечает чужие уведомления', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $foreign = storeUnreadNotification($other);

    Sanctum::actingAs($me);

    $this->patchJson('/api/notifications', ['notifications_ids' => [$foreign]])
        ->assertOk()
        ->assertJson(['updated' => 0]);

    expect(readAtOf($foreign))->toBeNull();
});

it('не помечает уведомления, переданные вместе с чужими', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $mine = storeUnreadNotification($me);
    $foreign = storeUnreadNotification($other);

    Sanctum::actingAs($me);

    $this->patchJson('/api/notifications', ['notifications_ids' => [$mine, $foreign]])
        ->assertOk()
        ->assertJson(['updated' => 1]);

    expect(readAtOf($mine))->not->toBeNull()
        ->and(readAtOf($foreign))->toBeNull();
});

it('пропускает несуществующие id и не перезаписывает уже прочитанные', function () {
    $me = User::factory()->create();
    $alreadyRead = '2026-01-01 10:00:00';
    $read = storeUnreadNotification($me, $alreadyRead);

    Sanctum::actingAs($me);

    $this->patchJson('/api/notifications', [
        'notifications_ids' => [(string) Str::uuid(), $read],
    ])
        ->assertOk()
        ->assertJson(['updated' => 0]);

    expect((string) readAtOf($read))->toBe($alreadyRead);
});

it('без ids ничего не обновляет', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->patchJson('/api/notifications')
        ->assertOk()
        ->assertJson(['updated' => 0]);
});

it('отклоняет не-UUID идентификаторы', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->patchJson('/api/notifications', ['notifications_ids' => ['not-a-uuid']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notifications_ids.0');
});
