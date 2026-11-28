<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

/**
 * Инициатор уже является учеником указанного преподавателя: форма запроса
 * не нужна. Несёт данные преподавателя, чтобы фронт сразу показал, к кому
 * уже есть связь: в `message` — токен `$user` (как в текстах уведомлений
 * RequestedAction), в `user` — `{id, name}` для его подстановки ссылкой.
 * HTTP-маппинг — 409 (bootstrap/app.php).
 */
final class AlreadyStudentOfTeacherException extends RequestedActionDomainException
{
    public function __construct(
        public readonly int $teacherId,
        public readonly string $teacherName,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forTeacher(int $teacherId, string $teacherName): self
    {
        return new self(
            teacherId: $teacherId,
            teacherName: $teacherName,
            // `$user` — токен, как в текстах уведомлений RequestedAction:
            // фронт подставляет его ссылкой по `user` из ответа.
            message: 'Вы уже являетесь учеником $user',
        );
    }
}
