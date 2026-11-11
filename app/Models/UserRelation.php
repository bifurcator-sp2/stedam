<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Тонкая Eloquent-модель ребра графа владения/наблюдения между пользователями.
 *
 * Доменной логики не содержит: это инфраструктурный факт связи.
 * Семантику scope (кто чьи прохождения видит) реализует
 * App\Infrastructure\...\EloquentContextResolver, читая эту таблицу.
 *
 * Типы связей (значение = роль хозяина ребра):
 *   teacher       teacher        → student
 *   parent        parent         → student
 *   methodologist methodologist  → teacher
 *   headteacher   headteacher    → methodologist
 */
class UserRelation extends Model
{
    use UsesTimestamptz;

    protected $fillable = [
        'parent_user_id',
        'child_user_id',
        'relation',
        'active_from',
        'active_to',
    ];

    protected $casts = [
        'parent_user_id' => 'integer',
        'child_user_id'  => 'integer',
        'active_from'    => 'datetime',
        'active_to'      => 'datetime',
    ];

    // ==================== Связи ====================

    /**
     * "Хозяин" связи (teacher / methodologist / headteacher / parent).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    /**
     * "Подчинённый" связи (student / teacher / methodologist).
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(User::class, 'child_user_id');
    }
}
