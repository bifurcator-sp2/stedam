<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Enums;

/**
 * Голос приёмщика: значение пишется в `requested_action_votes.decision`.
 *
 * Голос фиксирован: отозвать/переголосовать нельзя (UNIQUE на таблице голосов).
 */
enum VoteDecision: string
{
    case Accepted = 'accepted';
    case Declined = 'declined';
}
