<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\DTOs;

use App\Domain\RequestedActions\Entities\RequestedAction;
use App\Domain\RequestedActions\Enums\ApprovalResult;

/**
 * Результат `handler->registerApproval()`: новый экземпляр агрегата плюс
 * вердикт. Application-слой по нему решает, звать ли `save()` и какое
 * событие эмитить (2.4.2, 2.6).
 *
 * При WAITING/CLOSED агрегат в `$action` может быть тем же экземпляром —
 * мутаций не было.
 */
final readonly class RegisterApprovalOutcome
{
    public function __construct(
        public RequestedAction $action,
        public ApprovalResult $result,
    ) {}
}
