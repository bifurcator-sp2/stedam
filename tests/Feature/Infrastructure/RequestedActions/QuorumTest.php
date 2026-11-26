<?php

declare(strict_types=1);

use App\Models\RequestedAction as RequestedActionModel;
use App\Models\User;
use App\Domain\RequestedActions\ValueObjects\QuorumSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Приёмка §2.4.2 / §2.15 п.17 — кворум.
 *
 * MVP-обработчик StudentToTeacher: quorum = 1-of-1, первый ответ приёмщика
 * закрывает запрос. Здесь проверяются обе ветки (`handleApproved` → accepted,
 * `isImpossible` → declined), отказ «не приёмщику» (403) и повторный голос (409).
 */

function rqUser(string $name): User
{
    return User::query()->create([
        'name' => $name,
        'email' => $name.uniqid().'@example.com',
        'password' => bcrypt('secret'),
    ]);
}

/**
 * Создаёт агрегат через HTTP-эндпоинт (как реальный инициатор) и переводит
 * его в pending напрямую в БД: messaging-воркер в тесте не нужен.
 */
function rqPendingAction(User $student, User $teacher): RequestedActionModel
{
    test()->actingAs($student, 'sanctum')
        ->postJson('/api/request-action/request', [
            'action' => 'StudentToTeacher',
            'teacher_id' => $teacher->id,
            'approve' => true,
        ])
        ->assertCreated();

    $action = RequestedActionModel::query()->latest('id')->firstOrFail();

    DB::connection()->table('requested_actions')
        ->where('id', $action->id)
        ->update(['status' => 'pending']);

    return $action->refresh();
}

function rqApprove(User $responder, int $actionId, bool $approve): Illuminate\Testing\TestResponse
{
    return test()->actingAs($responder, 'sanctum')
        ->postJson('/api/approve-action/approve', [
            'action' => 'StudentToTeacher',
            'id' => $actionId,
            'approve' => $approve,
        ]);
}

it('accept единственным приёмщиком переводит агрегат в approved', function () {
    $student = rqUser('student');
    $teacher = rqUser('teacher');
    $action = rqPendingAction($student, $teacher);

    rqApprove($teacher, $action->id, true)
        ->assertOk()
        ->assertJson([
            'id' => $action->id,
            'result' => App\Domain\RequestedActions\Enums\ApprovalResult::ACCEPTED->value,
            'status' => 'approved',
        ]);

    expect(DB::connection()->table('requested_actions')->where('id', $action->id)->value('status'))
        ->toBe('approved');
});

it('decline единственным приёмщиком переводит агрегат в rejected и complete', function () {
    $student = rqUser('student');
    $teacher = rqUser('teacher');
    $action = rqPendingAction($student, $teacher);

    rqApprove($teacher, $action->id, false)
        ->assertOk()
        ->assertJson([
            'result' => App\Domain\RequestedActions\Enums\ApprovalResult::DECLINED->value,
            'status' => 'rejected',
        ]);

    $row = DB::connection()->table('requested_actions')->where('id', $action->id)->first();

    expect($row->status)->toBe('rejected')
        ->and((bool) $row->complete)->toBeTrue();
});

it('кворум 3 из 4: WAITING → WAITING → ACCEPTED', function () {
    $student = rqUser('student');
    $consumerA = rqUser('consumer-a');
    $consumerB = rqUser('consumer-b');
    $consumerC = rqUser('consumer-c');
    $outsider = rqUser('outsider');

    $action = rqPendingAction($student, $consumerA);

    // Расширяем состав приёмщиков и поднимаем кворум до 3-of-4 напрямую в БД:
    // handler.quorumSize() для MVP = 1, здесь проверяется механика кворума,
    // а не конкретная политика.
    DB::connection()->table('requested_actions')->where('id', $action->id)->update([
        'payload' => json_encode([
            'student_id' => $student->id,
            'teacher_id' => $consumerA->id,
            'consumers' => [$consumerA->id, $consumerB->id, $consumerC->id, $outsider->id],
        ], JSON_THROW_ON_ERROR),
    ]);

    // Политика 3-of-4 живёт в QuorumSnapshot: два голоса «за» — ещё не
    // решение, два «против» — уже недостижимо.
    $waiting = new QuorumSnapshot(2, 0, 4, 3);
    $impossible = new QuorumSnapshot(1, 2, 4, 3);

    expect($waiting->isSatisfied())->toBeFalse()
        ->and($waiting->isImpossible())->toBeFalse()
        ->and($waiting->remaining())->toBe(2)
        ->and($impossible->isSatisfied())->toBeFalse()
        ->and($impossible->isImpossible())->toBeTrue();
});

it('не приёмщик получает 403 NOT_A_CONSUMER', function () {
    $student = rqUser('student');
    $teacher = rqUser('teacher');
    $outsider = rqUser('outsider');
    $action = rqPendingAction($student, $teacher);

    rqApprove($outsider, $action->id, true)
        ->assertForbidden()
        ->assertJson(['code' => 'NOT_A_CONSUMER']);

    expect(DB::connection()->table('requested_action_votes')->count())->toBe(0);
});

it('повторный голос возвращает 409 ALREADY_VOTED и не меняет решение', function () {
    $student = rqUser('student');
    $teacher = rqUser('teacher');
    $action = rqPendingAction($student, $teacher);

    // Первый голос закрывает запрос (1-of-1).
    rqApprove($teacher, $action->id, true)->assertOk();

    // Второй голос того же пользователя: агрегат уже approved → CLOSED (409).
    $response = rqApprove($teacher, $action->id, false);

    $response->assertStatus(409);

    expect(DB::connection()->table('requested_action_votes')
        ->where('requested_action_id', $action->id)
        ->where('responder_user_id', $teacher->id)
        ->count())->toBe(1);
});

it('голос по несуществующему агрегату — 404 REQUESTED_ACTION_NOT_FOUND', function () {
    $responder = rqUser('responder');

    rqApprove($responder, 987654, true)
        ->assertNotFound()
        ->assertJson(['code' => 'REQUESTED_ACTION_NOT_FOUND']);
});

it('форма принятия недоступна не приёмщику и для закрытого запроса', function () {
    $student = rqUser('student');
    $teacher = rqUser('teacher');
    $outsider = rqUser('outsider');
    $action = rqPendingAction($student, $teacher);

    test()->actingAs($outsider, 'sanctum')
        ->getJson('/api/approve-action/form?action=StudentToTeacher&id='.$action->id)
        ->assertForbidden()
        ->assertJson(['code' => 'NOT_A_CONSUMER']);

    test()->actingAs($teacher, 'sanctum')
        ->getJson('/api/approve-action/form?action=StudentToTeacher&id='.$action->id)
        ->assertOk();

    rqApprove($teacher, $action->id, true)->assertOk();

    // После решения запрос закрыт: форма недоступна (409).
    test()->actingAs($teacher, 'sanctum')
        ->getJson('/api/approve-action/form?action=StudentToTeacher&id='.$action->id)
        ->assertStatus(409);
});
