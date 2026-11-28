<?php

declare(strict_types=1);

namespace App\Application;

/**
 * Направление чтения ребра графа — относительно текущего пользователя.
 *
 * Ребро всегда «хозяин → подчинённый» (см. App\Models\UserRelation):
 * одна и та же роль хозяина ребра даёт пару направлений чтения.
 * Например, для ребра teacher:
 *   - Outgoing — мои ученики  (я — teacher, хозяин ребра);
 *   - Incoming — мои учителя  (я — student, подчинённый ребра).
 *
 * Общий для всех контекстов (Learn/Community/...), чтобы не плодить
 * одинаковые enum'ы с разной семантикой.
 */
enum Direction: string
{
    /** Ребро, где текущий пользователь — parent_user_id. */
    case Outgoing = 'outgoing';

    /** Ребро, где текущий пользователь — child_user_id. */
    case Incoming = 'incoming';

    /**
     * true — текущий пользователь на стороне хозяина ребра
     * («кого я вижу в этой роли»), false — на стороне подчинённого
     * («кто видит меня в этой роли»).
     */
    public function isOutgoing(): bool
    {
        return $this === self::Outgoing;
    }
}
