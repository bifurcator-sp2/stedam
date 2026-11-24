<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

/**
 * Преподаватель, к которому идёт запрос, больше не существует
 * (`handle()` не может создать UserRelation).
 *
 * Постоянная ошибка: retry бессмысленен → *→error, error_code `TeacherDeleted`
 * (2.9, 2.15 п.11).
 */
final class TeacherDeletedException extends RequestedActionFatalException
{
    public static function withId(int $teacherId): self
    {
        return new self("Teacher {$teacherId} no longer exists");
    }
}
