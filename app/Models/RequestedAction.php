<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\Enums\VoteDecision;
use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;

/**
 * Тонкая Eloquent-модель агрегата RequestedAction.
 *
 * Доменные правила (переходы статусов, кворум) живут в
 * App\Domain\RequestedActions\Entities\RequestedAction. Здесь — только
 * маппинг БД. Запись идёт через репозиторий: строка лежит в помесячном
 * листе партиционированной таблицы, а `payload_hash` — generated column,
 * которую Eloquent не должен пытаться сохранять.
 */
class RequestedAction extends Model
{
    use UsesTimestamptz;

    protected $table = 'requested_actions';

    protected $fillable = [
        'user_id',
        'handler',
        'payload',
        'status',
        'decision',
        'complete',
        'error_code',
        'error_detail',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'payload' => 'array',
        'status' => RequestedActionStatus::class,
        'decision' => VoteDecision::class,
        'complete' => 'boolean',
    ];
}
