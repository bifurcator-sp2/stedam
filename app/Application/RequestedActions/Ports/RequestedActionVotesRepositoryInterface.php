<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Ports;

use App\Domain\RequestedActions\Enums\VoteDecision;
use App\Domain\RequestedActions\ValueObjects\QuorumSnapshot;
use App\Domain\RequestedActions\ValueObjects\RequestedActionRef;
use App\Domain\RequestedActions\ValueObjects\UserId;
use DateTimeImmutable;

/**
 * Порт кворумных голосов (таблица `requested_action_votes`, 2.4.1).
 *
 * Правила:
 *  - голос пишется INSERT ... ON CONFLICT DO NOTHING по UNIQUE
 *    (requested_action_id, requested_action_complete, requested_action_created_at,
 *    responder_user_id);
 *  - порт НЕ бросает исключений на конфликт — возвращает false; доменный
 *    AlreadyVotedException бросает handler (2.15 п.14);
 *  - FK на `requested_actions` нет: целостность обеспечивает Application
 *    (агрегат загружен FOR UPDATE) и retention (deleteForLeaf до DROP).
 */
interface RequestedActionVotesRepositoryInterface
{
    /**
     * @return bool true — голос вставлен; false — уже голосовал (конфликт)
     */
    public function record(RequestedActionRef $ref, UserId $responder, VoteDecision $decision): bool;

    /**
     * Снимок для правила раннего решения.
     */
    public function snapshot(RequestedActionRef $ref, int $total, int $quorum): QuorumSnapshot;

    public function countAll(RequestedActionRef $ref): int;

    /**
     * Удаляет голоса для помесячного листа [from, to) — retention (2.8.2).
     * Идемпотентен: повторный вызов по очищенному диапазону → 0.
     */
    public function deleteForLeaf(DateTimeImmutable $from, DateTimeImmutable $to): int;
}
