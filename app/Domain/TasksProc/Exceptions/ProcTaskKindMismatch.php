<?php

declare(strict_types=1);

namespace App\Domain\TasksProc\Exceptions;

use DomainException;

/**
 * Попытка работать с обычным заданием как с процедурным и наоборот.
 *
 * Инвариант: домены Tasks и TasksProc не пересекаются. Строка в БД
 * с is_proc = false — не процедурное задание и не должна попадать
 * в доменные методы TasksProc.
 */
final class ProcTaskKindMismatch extends DomainException
{
    public static function withId(int $id): self
    {
        return new self("LessonBlock {$id} is not a proc task");
    }
}
