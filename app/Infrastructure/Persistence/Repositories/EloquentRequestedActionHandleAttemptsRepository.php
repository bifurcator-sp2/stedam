<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\RequestedActions\Ports\RequestedActionHandleAttemptsRepositoryInterface;
use App\Domain\RequestedActions\ValueObjects\RequestedActionRef;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Eloquent-реализация порта попыток исполнения (2.7, 2.8.2).
 *
 * Строка пишется до вызова handle() отдельной транзакцией — это опора
 * stale-recovery: «есть строка» ⇔ «handle() запускался».
 */
final readonly class EloquentRequestedActionHandleAttemptsRepository implements RequestedActionHandleAttemptsRepositoryInterface
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function start(RequestedActionRef $ref): int
    {
        $attemptId = $this->db->connection()->table('requested_action_handle_attempts')->insertGetId([
            'requested_action_id' => $ref->id,
            'requested_action_complete' => $ref->complete,
            'requested_action_created_at' => $ref->createdAt->format('Y-m-d H:i:sP'),
            'started_at' => $this->now()->format('Y-m-d H:i:sP'),
            'finished_at' => null,
            'outcome' => null,
        ]);

        return (int) $attemptId;
    }

    public function finish(int $attemptId, string $outcome): void
    {
        $this->db->connection()->table('requested_action_handle_attempts')
            ->where('id', $attemptId)
            ->update([
                'finished_at' => $this->now()->format('Y-m-d H:i:sP'),
                'outcome' => $outcome,
            ]);
    }

    public function hasAttempts(RequestedActionRef $ref): bool
    {
        return $this->db->connection()->table('requested_action_handle_attempts')
            ->where('requested_action_id', $ref->id)
            ->where('requested_action_complete', $ref->complete)
            ->where('requested_action_created_at', $ref->createdAt->format('Y-m-d H:i:sP'))
            ->exists();
    }

    public function deleteForLeaf(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $this->db->connection()->table('requested_action_handle_attempts')
            ->where('requested_action_created_at', '>=', $from->format('Y-m-d H:i:sP'))
            ->where('requested_action_created_at', '<', $to->format('Y-m-d H:i:sP'))
            ->delete();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
