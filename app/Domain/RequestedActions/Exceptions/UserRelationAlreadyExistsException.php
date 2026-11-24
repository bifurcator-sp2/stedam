<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

/**
 * Связь «учитель → ученик» уже существует, но создана не этим агрегатом
 * (её `created_at` раньше `RequestedAction.created_at`).
 *
 * По правилу идемпотентности (2.15 п.4, 2.9) существующая по времени связь —
 * успех. Ошибка означает постороннюю запись (админка, ручной SQL), повторять
 * `handle()` бессмысленно → *→error, error_code `UserRelationAlreadyExists`.
 */
final class UserRelationAlreadyExistsException extends RequestedActionFatalException
{
    public static function forPair(int $teacherId, int $studentId): self
    {
        return new self(sprintf(
            'UserRelation %d→%d already exists and predates the requested action',
            $teacherId,
            $studentId,
        ));
    }
}
