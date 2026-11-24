<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\RequestedActions\Ports\RequestedActionVotesRepositoryInterface;
use App\Domain\RequestedActions\Enums\VoteDecision;
use App\Domain\RequestedActions\ValueObjects\QuorumSnapshot;
use App\Domain\RequestedActions\ValueObjects\RequestedActionRef;
use App\Domain\RequestedActions\ValueObjects\UserId;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Eloquent-реализация порта кворумных голосов (2.4.1).
 *
 * `record()` использует INSERT ... ON CONFLICT DO NOTHING по UNIQUE
 * (requested_action_id, requested_action_complete, requested_action_created_at,
 * responder_user_id) и возвращает false на конфликте — доменный
 * AlreadyVotedException бросает handler (2.15 п.14).
 */
final readonly class EloquentRequestedActionVotesRepository implements RequestedActionVotesRepositoryInterface
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function record(RequestedActionRef $ref, UserId $responder, VoteDecision $decision): bool
    {
        $row = $this->db->connection()->selectOne(
            'INSERT INTO requested_action_votes
                (requested_action_id, requested_action_complete, requested_action_created_at,
                 responder_user_id, decision, decided_at)
             VALUES (?, ?, ?, ?, ?, now())
             ON CONFLICT DO NOTHING
             RETURNING id',
            [
                $ref->id,
                $ref->complete,
                $ref->createdAt->format('Y-m-d H:i:sP'),
                $responder->value,
                $decision->value,
            ],
        );

        return $row !== null;
    }

    public function snapshot(RequestedActionRef $ref, int $total, int $quorum): QuorumSnapshot
    {
        $rows = $this->db->connection()->select(
            'SELECT decision, COUNT(*) AS cnt
               FROM requested_action_votes
              WHERE requested_action_id = ?
                AND requested_action_complete = ?
                AND requested_action_created_at = ?
              GROUP BY decision',
            [$ref->id, $ref->complete, $ref->createdAt->format('Y-m-d H:i:sP')],
        );

        $accepted = 0;
        $declined = 0;

        foreach ($rows as $row) {
            $decision = $row->decision instanceof VoteDecision
                ? $row->decision
                : VoteDecision::from((string) $row->decision);

            if ($decision === VoteDecision::Accepted) {
                $accepted = (int) $row->cnt;
            } else {
                $declined = (int) $row->cnt;
            }
        }

        return QuorumSnapshot::from($accepted, $declined, $total, $quorum);
    }

    public function countAll(RequestedActionRef $ref): int
    {
        return (int) $this->db->connection()->table('requested_action_votes')
            ->where('requested_action_id', $ref->id)
            ->where('requested_action_complete', $ref->complete)
            ->where('requested_action_created_at', $ref->createdAt->format('Y-m-d H:i:sP'))
            ->count();
    }

    public function deleteForLeaf(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $this->db->connection()->table('requested_action_votes')
            ->where('requested_action_created_at', '>=', $from->format('Y-m-d H:i:sP'))
            ->where('requested_action_created_at', '<', $to->format('Y-m-d H:i:sP'))
            ->delete();
    }
}
