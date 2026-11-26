<?php

declare(strict_types=1);

use App\Application\RequestedActions\Ports\RequestedActionHandleAttemptsRepositoryInterface;
use App\Application\RequestedActions\Ports\RequestedActionVotesRepositoryInterface;
use App\Domain\RequestedActions\Enums\VoteDecision;
use App\Domain\RequestedActions\ValueObjects\RequestedActionRef;
use App\Domain\RequestedActions\ValueObjects\UserId;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Приёмка §2.4.1 — голоса (votes) и попытки исполнения (attempts).
 *
 * Таблицы не партиционированы и не имеют FK на `requested_actions`:
 * строка агрегата переезжает между ветками при смене complete, поэтому
 * спорные строки допускаются на уровне БД, а целостность держат
 * Application (FOR UPDATE) и retention (`deleteForLeaf` до DROP листа).
 */

function rvRef(int $id, string $createdAt = '2026-01-10 12:00:00 UTC'): RequestedActionRef
{
    return new RequestedActionRef($id, false, new DateTimeImmutable($createdAt, new DateTimeZone('UTC')));
}

function rvUser(string $name): User
{
    return User::query()->create([
        'name' => $name,
        'email' => $name.uniqid().'@example.com',
        'password' => bcrypt('secret'),
    ]);
}

function rvVotes(): RequestedActionVotesRepositoryInterface
{
    return app(RequestedActionVotesRepositoryInterface::class);
}

function rvAttempts(): RequestedActionHandleAttemptsRepositoryInterface
{
    return app(RequestedActionHandleAttemptsRepositoryInterface::class);
}

it('первый голос записывается, повторный возвращает false', function () {
    $ref = rvRef(5001);
    $responder = rvUser('responder');

    expect(rvVotes()->record($ref, new UserId($responder->id), VoteDecision::Accepted))->toBeTrue()
        ->and(rvVotes()->record($ref, new UserId($responder->id), VoteDecision::Declined))->toBeFalse()
        ->and(rvVotes()->countAll($ref))->toBe(1);
});

it('snapshot агрегирует голоса по decision', function () {
    $ref = rvRef(5002);
    $first = rvUser('first');
    $second = rvUser('second');

    rvVotes()->record($ref, new UserId($first->id), VoteDecision::Accepted);
    rvVotes()->record($ref, new UserId($second->id), VoteDecision::Declined);

    $snapshot = rvVotes()->snapshot($ref, 4, 3);

    expect($snapshot->accepted)->toBe(1)
        ->and($snapshot->declined)->toBe(1)
        ->and($snapshot->total)->toBe(4)
        ->and($snapshot->quorum)->toBe(3)
        ->and($snapshot->remaining())->toBe(2)
        ->and($snapshot->isSatisfied())->toBeFalse()
        ->and($snapshot->isImpossible())->toBeFalse();
});

it('голос без строки агрегата допустим: FK на requested_actions нет', function () {
    $responder = rvUser('orphan-responder');
    $ref = rvRef(999999);

    expect(rvVotes()->record($ref, new UserId($responder->id), VoteDecision::Accepted))->toBeTrue()
        ->and(rvVotes()->snapshot($ref, 1, 1)->isSatisfied())->toBeTrue();
});

it('решение вне перечисления отклоняется CHECK-констрейнтом', function () {
    $responder = rvUser('check-responder');
    $ref = rvRef(5003);

    $failed = false;

    try {
        DB::connection()->table('requested_action_votes')->insert([
            'requested_action_id' => $ref->id,
            'requested_action_complete' => $ref->complete,
            'requested_action_created_at' => $ref->createdAt->format('Y-m-d H:i:sP'),
            'responder_user_id' => $responder->id,
            'decision' => 'maybe',
        ]);
    } catch (QueryException) {
        $failed = true;
    }

    expect($failed)->toBeTrue();
});

it('attempts: start пишет открытую строку, finish закрывает её', function () {
    $ref = rvRef(5004);

    $attemptId = rvAttempts()->start($ref);

    expect($attemptId)->toBeGreaterThan(0)
        ->and(rvAttempts()->hasAttempts($ref))->toBeTrue();

    $row = DB::connection()->table('requested_action_handle_attempts')->where('id', $attemptId)->first();

    expect($row->finished_at)->toBeNull()
        ->and($row->outcome)->toBeNull();

    rvAttempts()->finish($attemptId, 'ok');

    $row = DB::connection()->table('requested_action_handle_attempts')->where('id', $attemptId)->first();

    expect($row->finished_at)->not->toBeNull()
        ->and($row->outcome)->toBe('ok');
});

it('attempts: hasAttempts не путает разные ключи идентичности', function () {
    $started = rvRef(5005);
    $untouched = rvRef(5006);

    rvAttempts()->start($started);

    expect(rvAttempts()->hasAttempts($started))->toBeTrue()
        ->and(rvAttempts()->hasAttempts($untouched))->toBeFalse();
});

it('deleteForLeaf удаляет голоса и попытки только своего диапазона', function () {
    $inside = rvRef(5007, '2026-01-15 00:00:00 UTC');
    $outside = rvRef(5008, '2026-02-15 00:00:00 UTC');
    $responder = rvUser('range-responder');

    rvVotes()->record($inside, new UserId($responder->id), VoteDecision::Accepted);
    rvVotes()->record($outside, new UserId($responder->id), VoteDecision::Accepted);
    rvAttempts()->start($inside);
    rvAttempts()->start($outside);

    $from = new DateTimeImmutable('2026-01-01 00:00:00 UTC');
    $to = new DateTimeImmutable('2026-02-01 00:00:00 UTC');

    expect(rvVotes()->deleteForLeaf($from, $to))->toBe(1)
        ->and(rvAttempts()->deleteForLeaf($from, $to))->toBe(1)
        // Идемпотентность: повтор по очищенному диапазону ничего не находит.
        ->and(rvVotes()->deleteForLeaf($from, $to))->toBe(0)
        ->and(rvAttempts()->hasAttempts($inside))->toBeFalse()
        ->and(rvAttempts()->hasAttempts($outside))->toBeTrue()
        ->and(rvVotes()->countAll($outside))->toBe(1);
});
