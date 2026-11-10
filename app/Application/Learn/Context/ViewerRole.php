<?php

declare(strict_types=1);

namespace App\Application\Learn\Context;

/**
 * Роли, релевантные контексту Learn.
 *
 * Роли пользователей хранятся в Spatie (App\Models\User::hasRole).
 * Здесь — типизированное представление имён, чтобы не хардкодить строки
 * по коду Application/Infrastructure.
 *
 * ВНИМАНИЕ: это НЕ полный список ролей приложения (есть admin и др.) —
 * только те, что участвуют в построении ViewerContext.
 */
enum ViewerRole: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Parent = 'parent';
    case Methodologist = 'methodologist';
    case Headteacher = 'headteacher';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
