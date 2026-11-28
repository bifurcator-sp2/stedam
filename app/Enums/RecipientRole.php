<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Сторона участника процесса RequestedAction в конкретном уведомлении.
 *
 * Инициатор действия — `requested_actions.user_id` (или `payload.student_id`
 * в текущем handler'е). Одобряющие — `payload.consumers`. Роль известна
 * заранее в момент отправки (handler обходит конкретных получателей в
 * `notify()`), поэтому пишется в `data.recipient_role` уведомления и
 * переиспользуется списком (`GET /api/notifications`) без повторного вывода.
 */
enum RecipientRole: string
{
    case Initiator = 'initiator';
    case Consumer  = 'consumer';
}
