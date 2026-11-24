<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;

/**
 * Тонкая Eloquent-модель попытки исполнения approved-агрегата (2.7).
 *
 * Без timestamps Laravel: таблица ведёт `started_at` / `finished_at`.
 */
class RequestedActionHandleAttempt extends Model
{
    use UsesTimestamptz;

    protected $table = 'requested_action_handle_attempts';

    public $timestamps = false;

    protected $fillable = [
        'requested_action_id',
        'requested_action_complete',
        'requested_action_created_at',
        'started_at',
        'finished_at',
        'outcome',
    ];

    protected $casts = [
        'requested_action_id' => 'integer',
        'requested_action_complete' => 'boolean',
        'requested_action_created_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];
}
