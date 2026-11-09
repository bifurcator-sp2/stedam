<?php

declare(strict_types=1);

namespace App\Domain\Learn\Enums;

enum LearnCategory: string
{
    case Assigned        = 'assigned';          // created
    case ScheduledToday  = 'scheduled_today';   // scheduled + дата сегодня
    case ScheduledFuture = 'scheduled_future';  // scheduled + дата в будущем
    case Overdue         = 'overdue';           // scheduled + дата в прошлом
    case InProgress      = 'in_progress';       // ученик начал
    case AwaitingGrade   = 'awaiting_grade';    // done
    case Graded          = 'graded';            // graded
}
