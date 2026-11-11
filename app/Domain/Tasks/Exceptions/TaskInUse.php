<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Exceptions;

use DomainException;

/**
 * Задание уже привязано к урокам, поэтому часть изменений запрещена.
 *
 * Инвариант: смена набора таксономий у задания, которое уже используется,
 * нарушила бы консистентность уроков (задание, привязанное к разделу A,
 * вдруг оказалось бы в уроке раздела B).
 */
final class TaskInUse extends DomainException
{
    public static function taxonomyChangeDenied(int $taskId): self
    {
        return new self('Нельзя сменить раздел у задания, которое уже используется в уроках.');
    }
}
