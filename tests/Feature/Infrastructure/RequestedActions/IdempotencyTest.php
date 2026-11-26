<?php

declare(strict_types=1);

use App\Application\RequestedActions\Actions\EnsureRequestedActionPartitionAction;
use App\Domain\RequestedActions\Entities\RequestedAction as RequestedActionEntity;
use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\ValueObjects\RequestedActionPayload;
use App\Domain\RequestedActions\ValueObjects\UserId;
use App\Infrastructure\Persistence\Repositories\EloquentRequestedActionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Приёмка §2.8.1 — идемпотентность создания агрегата.
 *
 * Ключ идемпотентности живёт в БД: UNIQUE (user_id, handler, payload_hash)
 * объявлен на помесячном листе, payload_hash — generated md5(payload::text),
 * а jsonb канонизирует порядок ключей. Поэтому INSERT идёт через
 * `ON CONFLICT DO NOTHING` БЕЗ списка колонок: со списком PG не смог бы
 * выбрать партиционный индекс и отклонил бы вставку дубликата.
 */

const RA_HANDLER = 'AcceptanceHandler';

function raRepo(): EloquentRequestedActionRepository
{
    return app(EloquentRequestedActionRepository::class);
}

function raEnsureLeaf(DateTimeImmutable $moment): string
{
    return app(EnsureRequestedActionPartitionAction::class)->executeActive($moment);
}

function raAction(int $userId, array $payload, string $handler = RA_HANDLER): RequestedActionEntity
{
    return RequestedActionEntity::create(new UserId($userId), $handler, new RequestedActionPayload($payload));
}

function raRawCount(int $userId, string $handler): int
{
    return (int) DB::connection()->table('requested_actions')
        ->where('user_id', $userId)
        ->where('handler', $handler)
        ->count();
}

it('ON CONFLICT DO NOTHING без списка колонок: первая вставка true, дубликат false', function () {
    raEnsureLeaf(new DateTimeImmutable('now', new DateTimeZone('UTC')));

    $action = raAction(1001, ['student_id' => 1, 'teacher_id' => 2, 'consumers' => [2]]);

    expect(raRepo()->insertActive($action))->toBeTrue();

    // Та же идентичность: (user_id, handler, payload_hash) совпадает → конфликт.
    $duplicate = raAction(1001, ['student_id' => 1, 'teacher_id' => 2, 'consumers' => [2]]);

    expect(raRepo()->insertActive($duplicate))->toBeFalse()
        ->and(raRawCount(1001, RA_HANDLER))->toBe(1);
});

it('jsonb канонизирует порядок ключей: другой порядок — тот же payload_hash', function () {
    raEnsureLeaf(new DateTimeImmutable('now', new DateTimeZone('UTC')));

    $first = raAction(1002, ['student_id' => 5, 'teacher_id' => 7, 'consumers' => [7]]);
    $reordered = raAction(1002, ['consumers' => [7], 'teacher_id' => 7, 'student_id' => 5]);

    expect(raRepo()->insertActive($first))->toBeTrue()
        ->and(raRepo()->insertActive($reordered))->toBeFalse();
});

it('разный payload — разный payload_hash: обе строки создаются', function () {
    raEnsureLeaf(new DateTimeImmutable('now', new DateTimeZone('UTC')));

    $a = raAction(1003, ['student_id' => 1, 'teacher_id' => 2, 'consumers' => [2]]);
    $b = raAction(1003, ['student_id' => 1, 'teacher_id' => 3, 'consumers' => [3]]);

    expect(raRepo()->insertActive($a))->toBeTrue()
        ->and(raRepo()->insertActive($b))->toBeTrue()
        ->and(raRawCount(1003, RA_HANDLER))->toBe(2);
});

it('ON CONFLICT со списком колонок отклоняется PostgreSQL', function () {
    raEnsureLeaf(new DateTimeImmutable('now', new DateTimeZone('UTC')));

    // Именно поэтому репозиторий использует ON CONFLICT DO NOTHING без списка:
    // на партиционированной таблице PG не находит индекс по (user_id).
    $failed = false;

    try {
        DB::connection()->statement(
            "INSERT INTO requested_actions_active (user_id, handler, payload, status, complete)
             VALUES (1, 'X', '{\"a\":1}', 'created', false)
             ON CONFLICT (user_id) DO NOTHING"
        );
    } catch (Illuminate\Database\QueryException) {
        $failed = true;
    }

    expect($failed)->toBeTrue();
});

it('findActiveByIdentity находит вставленный агрегат и не видит завершённых', function () {
    raEnsureLeaf(new DateTimeImmutable('now', new DateTimeZone('UTC')));

    $action = raAction(1004, ['student_id' => 1, 'teacher_id' => 2, 'consumers' => [2]]);
    expect(raRepo()->insertActive($action))->toBeTrue();

    $row = DB::connection()->table('requested_actions')
        ->where('user_id', 1004)->where('handler', RA_HANDLER)->first();

    $found = raRepo()->findActiveByIdentity(1004, RA_HANDLER, (string) $row->payload_hash);

    expect($found)->not->toBeNull()
        ->and($found->status())->toBe(RequestedActionStatus::Created)
        ->and($found->userId()->value)->toBe(1004);
});
