<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Enums;


/**
 * Результат вызова `handler->registerApproval()` (раздел 1, ApprovalResult).
 *
 *   ACCEPTED — голос accepted, кворум собран: pending → approved.
 *   DECLINED — голос declined, кворум уже недостижим: pending → rejected.
 *   WAITING  — голос записан, но кворум пока не собран и ещё возможен.
 *   CLOSED   — агрегат не в pending (approved/rejected/done/error), ничего не меняется.
 */
enum ApprovalResult: string
{
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case WAITING = 'waiting';
    case CLOSED = 'closed';

    public function isDecisive(): bool
    {
        return $this === self::ACCEPTED || $this === self::DECLINED;
    }

    /**
     * Итоговое решение кворума для колонки `requested_actions.decision`
     * (раздел 1): ACCEPTED/DECLINED — решение принято, WAITING/CLOSED — нет.
     * Значения совпадают со значениями VoteDecision.
     */
    public function toVoteDecision(): ?VoteDecision
    {
        return match ($this) {
            self::ACCEPTED => VoteDecision::Accepted,
            self::DECLINED => VoteDecision::Declined,
            self::WAITING, self::CLOSED => null,
        };
    }
}
