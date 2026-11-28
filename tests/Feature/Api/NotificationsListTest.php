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
 * @param  array<string, mixed>  $data
 */
function storeNotification(User $user, array $data, ?string $readAt = null): string
{
    $id = (string) Str::uuid();

    $user->notifications()->create([
        'id' => $id,
        'type' => RequestedActionNotification::class,
        'data' => $data,
        'read_at' => $readAt,
    ]);

    return $id;
}

// ---------------------------------------------------------------------
// Доступ
// ---------------------------------------------------------------------

it('гость не имеет доступа к списку уведомлений', function () {
    $this->getJson('/api/notifications')->assertUnauthorized();
});

// ---------------------------------------------------------------------
// Фильтр по получателю
// ---------------------------------------------------------------------

it('отдаёт только уведомления текущего пользователя', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    $mine = storeNotification($me, ['type' => 'request', 'title' => 'Моё']);
    storeNotification($other, ['type' => 'request', 'title' => 'Чужое']);

    Sanctum::actingAs($me);

    $response = $this->getJson('/api/notifications')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($mine);
});

// ---------------------------------------------------------------------
// Форма элемента
// ---------------------------------------------------------------------

it('в элементе отдаёт id, короткое имя класса type, data, date и read', function () {
    $me = User::factory()->create();
    $data = ['type' => 'approved', 'title' => 'Запрос принят', 'text' => 'Текст'];

    storeNotification($me, $data);

    Sanctum::actingAs($me);

    $item = $this->getJson('/api/notifications')->assertOk()->json('data.0');

    expect(array_keys($item))->toBe(['id', 'type', 'data', 'date', 'read'])
        // type в БД — FQCN, наружу — короткое имя (кейс NotificationType).
        ->and($item['type'])->toBe('RequestedAction')
        ->and($item['data'])->toEqual($data)
        ->and($item['read'])->toBeFalse();
});

it('отдаёт read = true для прочитанного уведомления', function () {
    $me = User::factory()->create();

    storeNotification($me, ['type' => 'request', 'title' => 'Запрос'], readAt: '2026-01-01 10:00:00');

    Sanctum::actingAs($me);

    expect($this->getJson('/api/notifications')->assertOk()->json('data.0.read'))
        ->toBeTrue();
});

// ---------------------------------------------------------------------
// Фильтр по прочтению (status)
// ---------------------------------------------------------------------

it('по умолчанию отдаёт и прочитанные, и непрочитанные', function () {
    $me = User::factory()->create();

    storeNotification($me, ['type' => 'request', 'title' => 'Новое']);
    storeNotification($me, ['type' => 'done', 'title' => 'Старое'], readAt: '2026-01-01 10:00:00');

    Sanctum::actingAs($me);

    expect($this->getJson('/api/notifications')->assertOk()->json('data'))
        ->toHaveCount(2);
});

it('status=unread отдаёт только непрочитанные', function () {
    $me = User::factory()->create();

    $unread = storeNotification($me, ['type' => 'request', 'title' => 'Новое']);
    storeNotification($me, ['type' => 'done', 'title' => 'Старое'], readAt: '2026-01-01 10:00:00');

    Sanctum::actingAs($me);

    $data = $this->getJson('/api/notifications?status=unread')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($unread)
        ->and($data[0]['read'])->toBeFalse();
});

it('status=read отдаёт только прочитанные', function () {
    $me = User::factory()->create();

    storeNotification($me, ['type' => 'request', 'title' => 'Новое']);
    $read = storeNotification($me, ['type' => 'done', 'title' => 'Старое'], readAt: '2026-01-01 10:00:00');

    Sanctum::actingAs($me);

    $data = $this->getJson('/api/notifications?status=read')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($read)
        ->and($data[0]['read'])->toBeTrue();
});

it('неизвестный status не фильтрует', function () {
    $me = User::factory()->create();

    storeNotification($me, ['type' => 'request', 'title' => 'Новое']);
    storeNotification($me, ['type' => 'done', 'title' => 'Старое'], readAt: '2026-01-01 10:00:00');

    Sanctum::actingAs($me);

    expect($this->getJson('/api/notifications?status=bogus')->assertOk()->json('data'))
        ->toHaveCount(2);
});

// ---------------------------------------------------------------------
// types (реестр классов) и action_types (смысл сообщения)
// ---------------------------------------------------------------------

it('в ответе отдаёт справочник types с названиями по короткому имени класса', function () {
    $me = User::factory()->create();
    storeNotification($me, ['type' => 'request', 'title' => 'Запрос']);

    Sanctum::actingAs($me);

    $types = $this->withHeader('Accept-Language', 'ru')
        ->getJson('/api/notifications')->assertOk()->json('types');

    expect($types)->toHaveKey('RequestedAction')
        ->and($types['RequestedAction'])->toBe('Запрос на подтверждение действия');
});

it('в ответе отдаёт справочник action_types с локализованными названиями', function () {
    Sanctum::actingAs(User::factory()->create());

    $types = $this->getJson('/api/notifications')->assertOk()->json('action_types');

    expect($types)->toHaveKeys(['request', 'approved', 'rejected', 'done', 'error']);

    foreach ($types as $label) {
        expect($label)->toBeString()
            ->and($label)->not->toStartWith('notifications.');
    }
});

// ---------------------------------------------------------------------
// Поиск по data.title
// ---------------------------------------------------------------------

it('ищет по data.title без учёта регистра', function () {
    $me = User::factory()->create();

    $match = storeNotification($me, ['type' => 'request', 'title' => 'Запрос на запись']);
    storeNotification($me, ['type' => 'done', 'title' => 'Действие выполнено']);

    Sanctum::actingAs($me);

    $data = $this->getJson('/api/notifications?search=ЗАПРОС')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($match);
});

// ---------------------------------------------------------------------
// Актуальный статус RequestedAction
// ---------------------------------------------------------------------

/**
 * Записать строку заявки в партиционированную requested_actions
 * (лист текущего месяца создаётся миграцией).
 */
function storeRequestedAction(int $userId, string $status): int
{
    return (int) DB::table('requested_actions')->insertGetId([
        'user_id' => $userId,
        'handler' => 'StudentToTeacher',
        'payload' => json_encode([]),
        'status' => $status,
        'complete' => false,
    ]);
}

it('для RequestedAction подтягивает актуальный status из requested_actions', function () {
    $me = User::factory()->create();
    $actionId = storeRequestedAction($me->id, 'done');

    storeNotification($me, [
        'type' => 'request',
        'requested_action_id' => $actionId,
        'status' => 'pending',
        'title' => 'Запрос',
    ]);

    Sanctum::actingAs($me);

    $data = $this->getJson('/api/notifications')->assertOk()->json('data.0.data');

    expect($data['status'])->toBe('done')
        ->and($data['requested_action_id'])->toBe($actionId);
});

it('без записи в requested_actions оставляет data.status как есть', function () {
    $me = User::factory()->create();

    storeNotification($me, [
        'type' => 'request',
        'requested_action_id' => 999999,
        'status' => 'pending',
        'title' => 'Запрос',
    ]);

    Sanctum::actingAs($me);

    expect($this->getJson('/api/notifications')->assertOk()->json('data.0.data.status'))
        ->toBe('pending');
});

// ---------------------------------------------------------------------
// Пагинация
// ---------------------------------------------------------------------

it('пагинирует список и отдаёт meta', function () {
    $me = User::factory()->create();

    foreach (range(1, 3) as $i) {
        storeNotification($me, ['type' => 'request', 'title' => "Уведомление {$i}"]);
    }

    Sanctum::actingAs($me);

    $response = $this->getJson('/api/notifications?per_page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.current_page'))->toBe(1)
        ->and($response->json('meta.per_page'))->toBe(2)
        ->and($response->json('meta.has_more_pages'))->toBeTrue();
});
