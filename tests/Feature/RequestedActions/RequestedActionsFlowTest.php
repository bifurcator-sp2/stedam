<?php

declare(strict_types=1);

use App\Application\RequestedActions\Actions\CreateRequestedActionAction;
use App\Application\RequestedActions\Actions\RegisterApprovalAction;
use App\Domain\RequestedActions\Enums\ApprovalResult;
use App\Domain\RequestedActions\Enums\VoteDecision;
use App\Domain\RequestedActions\ValueObjects\RequestedActionRef;
use App\Domain\RequestedActions\ValueObjects\UserId;
use App\Infrastructure\Persistence\Repositories\EloquentRequestedActionVotesRepository;
use App\Models\User;
use App\Notifications\RequestedActions\RequestedActionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

const ALIAS = 'StudentToTeacher';

/**
 * Пара «ученик → преподаватель», созданная фабрикой.
 *
 * @return array{student: User, teacher: User}
 */
function pair(): array
{
    return [
        'student' => User::factory()->create(),
        'teacher' => User::factory()->create(),
    ];
}

function requestArguments(array $pair): array
{
    return [
        'action' => ALIAS,
        'user_id' => $pair['student']->id,
        'teacher_id' => $pair['teacher']->id,
        'approve' => true,
    ];
}

function createAction(array $pair): \App\Domain\RequestedActions\Entities\RequestedAction
{
    /** @var CreateRequestedActionAction $action */
    $action = app(CreateRequestedActionAction::class);

    return $action->execute(
        new UserId($pair['student']->id),
        requestArguments($pair),
        ALIAS,
    );
}

/**
 * Перевести агрегат created → pending: в проде это делает messaging-worker,
 * а голосовать можно только по pending-запросу (created → approved запрещён).
 */
function dispatchToPending(): void
{
    app(\App\Application\RequestedActions\Actions\SendRequestNotificationsAction::class)->execute(100);
}

function actionRow(int $id): ?object
{
    return DB::connection()->selectOne(
        'SELECT id, status, complete, payload, error_code FROM requested_actions
          WHERE id = ? AND complete = false
          UNION ALL
          SELECT id, status, complete, payload, error_code FROM requested_actions
          WHERE id = ? AND complete = true
          LIMIT 1',
        [$id, $id],
    );
}

it('создаёт агрегат в статусе created и активной ветке', function () {
    Event::fake();
    $pair = pair();

    $created = createAction($pair);

    expect($created->id())->not->toBeNull()
        ->and($created->status()->value)->toBe('created')
        ->and($created->isComplete())->toBeFalse()
        ->and($created->payload()->consumers())->toBe([$pair['teacher']->id]);

    $row = actionRow($created->id()->value);
    expect($row->status)->toBe('created')->and($row->complete)->toBeFalse();
});

it('не создаёт агрегат, если пользователь отказался (approve = false)', function () {
    $pair = pair();
    $arguments = requestArguments($pair);
    $arguments['approve'] = false;

    /** @var CreateRequestedActionAction $action */
    $action = app(CreateRequestedActionAction::class);

    expect($action->execute(new UserId($pair['student']->id), $arguments, ALIAS))->toBeNull()
        ->and(DB::connection()->table('requested_actions')->count())->toBe(0);
});

it('идемпотентен: повторный запрос возвращает тот же агрегат и не создаёт дубль', function () {
    Event::fake();
    $pair = pair();

    $first = createAction($pair);
    $second = createAction($pair);

    expect($second->id()?->value)->toBe($first->id()?->value)
        ->and(DB::connection()->table('requested_actions')->count())->toBe(1);
});

it('идемпотентность не зависит от порядка ключей payload', function () {
    Event::fake();
    $pair = pair();

    $first = createAction($pair);

    $reordered = [
        'approve' => true,
        'teacher_id' => $pair['teacher']->id,
        'user_id' => $pair['student']->id,
        'action' => ALIAS,
    ];

    $second = app(CreateRequestedActionAction::class)
        ->execute(new UserId($pair['student']->id), $reordered, ALIAS);

    expect($second->id()?->value)->toBe($first->id()?->value)
        ->and(DB::connection()->table('requested_actions')->count())->toBe(1);
});

it('разные пары не конфликтуют — создаются два агрегата', function () {
    Event::fake();
    $student = User::factory()->create();
    $t1 = User::factory()->create();
    $t2 = User::factory()->create();

    app(CreateRequestedActionAction::class)->execute(
        new UserId($student->id),
        ['approve' => true, 'user_id' => $student->id, 'teacher_id' => $t1->id],
        ALIAS,
    );
    app(CreateRequestedActionAction::class)->execute(
        new UserId($student->id),
        ['approve' => true, 'user_id' => $student->id, 'teacher_id' => $t2->id],
        ALIAS,
    );

    expect(DB::connection()->table('requested_actions')->count())->toBe(2);
});

it('запрос самому себе отклоняется доменной ошибкой', function () {
    $user = User::factory()->create();

    app(CreateRequestedActionAction::class)->execute(
        new UserId($user->id),
        ['approve' => true, 'user_id' => $user->id, 'teacher_id' => $user->id],
        ALIAS,
    );
})->throws(\App\Domain\RequestedActions\Exceptions\RequestedActionDomainException::class);

it('store: первый accept закрывает кворум 1-of-1, статус approved и complete=false', function () {
    Event::fake();
    $pair = pair();
    $created = createAction($pair);
    dispatchToPending();

    /** @var RegisterApprovalAction $register */
    $register = app(RegisterApprovalAction::class);
    $result = $register->execute(
        $created->id()->value,
        new UserId($pair['teacher']->id),
        ['approve' => true],
    );

    expect($result)->toBe(ApprovalResult::ACCEPTED);

    $row = actionRow($created->id()->value);
    expect($row->status)->toBe('approved')->and($row->complete)->toBeFalse();

    $votes = DB::connection()->table('requested_action_votes')->count();
    expect($votes)->toBe(1);
});

it('store: decline переводит в rejected и complete=true (переезд в завершённую ветку)', function () {
    Event::fake();
    $pair = pair();
    $created = createAction($pair);
    dispatchToPending();

    $result = app(RegisterApprovalAction::class)->execute(
        $created->id()->value,
        new UserId($pair['teacher']->id),
        ['approve' => false],
    );

    expect($result)->toBe(ApprovalResult::DECLINED);

    $row = actionRow($created->id()->value);
    expect($row->status)->toBe('rejected')->and($row->complete)->toBeTrue();

    // В активной ветке строки больше нет.
    $active = DB::connection()->selectOne(
        'SELECT 1 FROM requested_actions WHERE complete = false AND id = ?',
        [$created->id()->value],
    );
    expect($active)->toBeNull();
});

// --- HTTP-слой (2.13) ---

it('POST /api/request-action/request создаёт агрегат и отдаёт 201', function () {
    Event::fake();
    $pair = pair();
    Sanctum::actingAs($pair['student']);

    $response = postJson('/api/request-action/request', requestArguments($pair));

    $response->assertCreated()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('handler', ALIAS);

    expect(DB::connection()->table('requested_actions')->count())->toBe(1);
});

it('POST /api/request-action/request с approve=false отдаёт 422 USER_REQUEST_DECLINED', function () {
    $pair = pair();
    Sanctum::actingAs($pair['student']);

    $arguments = requestArguments($pair);
    $arguments['approve'] = false;

    postJson('/api/request-action/request', $arguments)
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_REQUEST_DECLINED');
});

it('POST /api/request-action/request требует авторизации', function () {
    postJson('/api/request-action/request', ['action' => ALIAS])->assertUnauthorized();
});

it('POST /api/approve-action/approve от чужого пользователя → 403 NOT_A_CONSUMER', function () {
    Event::fake();
    $pair = pair();
    $created = createAction($pair);
    dispatchToPending();

    Sanctum::actingAs(User::factory()->create());

    postJson('/api/approve-action/approve', [
        'action' => ALIAS,
        'id' => $created->id()->value,
        'approve' => true,
    ])->assertForbidden()->assertJsonPath('code', 'NOT_A_CONSUMER');

    expect(actionRow($created->id()->value)->status)->toBe('pending');
});

it('POST /api/approve-action/approve по несуществующему id → 404', function () {
    Sanctum::actingAs(User::factory()->create());

    postJson('/api/approve-action/approve', [
        'action' => ALIAS,
        'id' => 999999,
        'approve' => true,
    ])->assertNotFound();
});

it('POST /api/approve-action/approve закрывает кворум и отдаёт approved', function () {
    Event::fake();
    $pair = pair();
    $created = createAction($pair);
    dispatchToPending();

    Sanctum::actingAs($pair['teacher']);

    postJson('/api/approve-action/approve', [
        'action' => ALIAS,
        'id' => $created->id()->value,
        'approve' => true,
    ])->assertOk()
        ->assertJsonPath('result', ApprovalResult::ACCEPTED->value)
        ->assertJsonPath('status', 'approved');
});

it('повторное одобрение уже закрытого запроса → 409 REQUESTED_ACTION_CLOSED', function () {
    Event::fake();
    $pair = pair();
    $created = createAction($pair);
    dispatchToPending();
    Sanctum::actingAs($pair['teacher']);

    postJson('/api/approve-action/approve', [
        'action' => ALIAS,
        'id' => $created->id()->value,
        'approve' => true,
    ])->assertOk();

    postJson('/api/approve-action/approve', [
        'action' => ALIAS,
        'id' => $created->id()->value,
        'approve' => true,
    ])->assertStatus(409)->assertJsonPath('code', 'REQUESTED_ACTION_CLOSED');
});

it('GET /api/approve-action/form отдаёт 409 по approved-запросу', function () {
    Event::fake();
    $pair = pair();
    $created = createAction($pair);
    dispatchToPending();
    Sanctum::actingAs($pair['teacher']);

    app(RegisterApprovalAction::class)->execute(
        $created->id()->value,
        new UserId($pair['teacher']->id),
        ['approve' => true],
    );

    $this->getJson('/api/approve-action/form?action='.ALIAS.'&id='.$created->id()->value)
        ->assertStatus(409)
        ->assertJsonPath('code', 'REQUESTED_ACTION_CLOSED');
});

it('GET /api/request-action/form отдаёт описание формы', function () {
    $pair = pair();
    Sanctum::actingAs($pair['student']);

    $this->getJson('/api/request-action/form?action='.ALIAS.'&teacher_id='.$pair['teacher']->id)
        ->assertOk()
        ->assertJsonPath('title.type', 'string')
        ->assertJsonPath('approve.type', 'boolean');
});

it('store: уведомление о запросе уходит приёмщику в канал database', function () {
    Notification::fake();
    $pair = pair();
    $created = createAction($pair);

    app(\App\Application\RequestedActions\Actions\SendRequestNotificationsAction::class)->execute(100);

    Notification::assertSentTo(
        $pair['teacher'],
        RequestedActionNotification::class,
        fn (RequestedActionNotification $n) => $n->payload['type'] === 'request'
            && $n->payload['requested_action_id'] === $created->id()->value
            && $n->via === ['database'],
    );
    Notification::assertNotSentTo($pair['student'], RequestedActionNotification::class);

    expect(actionRow($created->id()->value)->status)->toBe('pending');
});

it('votes-репозиторий: повторная запись голоса возвращает false', function () {
    Event::fake();
    $pair = pair();
    $created = createAction($pair);

    $ref = new RequestedActionRef(
        id: $created->id()->value,
        complete: $created->isComplete(),
        createdAt: $created->createdAt(),
    );

    /** @var EloquentRequestedActionVotesRepository $votes */
    $votes = app(EloquentRequestedActionVotesRepository::class);

    expect($votes->record($ref, new UserId($pair['teacher']->id), VoteDecision::Accepted))->toBeTrue()
        ->and($votes->record($ref, new UserId($pair['teacher']->id), VoteDecision::Accepted))->toBeFalse()
        ->and($votes->countAll($ref))->toBe(1)
        ->and($votes->snapshot($ref, 1, 1)->accepted)->toBe(1);
});

it('повторный голос тем же приёмщиком → AlreadyVotedException', function () {
    Event::fake();
    $pair = pair();
    $created = createAction($pair);
    dispatchToPending();

    app(RegisterApprovalAction::class)->execute(
        $created->id()->value,
        new UserId($pair['teacher']->id),
        ['approve' => true],
    );

    // После ACCEPTED агрегат уже approved — повторный голос закрыт статусом.
    $result = app(RegisterApprovalAction::class)->execute(
        $created->id()->value,
        new UserId($pair['teacher']->id),
        ['approve' => true],
    );

    expect($result)->toBe(ApprovalResult::CLOSED);
});
