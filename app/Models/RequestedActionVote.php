<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\RequestedActions\Enums\VoteDecision;
use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;

/**
 * Тонкая Eloquent-модель голоса кворума (2.4.1).
 *
 * `$timestamps = false` — таблица хранит только `decided_at`.
 * FK на `requested_actions` нет: строка агрегата переезжает между
 * партициями, целостность держат Application и retention.
 */
class RequestedActionVote extends Model
{
    use UsesTimestamptz;

    protected $table = 'requested_action_votes';

    public $timestamps = false;

    protected $fillable = [
        'requested_action_id',
        'requested_action_complete',
        'requested_action_created_at',
        'responder_user_id',
        'decision',
        'decided_at',
    ];

    protected $casts = [
        'requested_action_id' => 'integer',
        'requested_action_complete' => 'boolean',
        'requested_action_created_at' => 'immutable_datetime',
        'responder_user_id' => 'integer',
        'decision' => VoteDecision::class,
        'decided_at' => 'immutable_datetime',
    ];
}
