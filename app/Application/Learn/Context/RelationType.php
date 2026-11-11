<?php

declare(strict_types=1);

namespace App\Application\Learn\Context;

/**
 * Типы рёбер графа владения/наблюдения (user_relations.relation).
 *
 * Источник правды для строкового значения `relation` в БД.
 * Application-слой; Infrastructure читает значения отсюда.
 *
 * Значение = роль «хозяина» ребра (кем является parent_user_id по
 * отношению к child_user_id). Читается буквально в БД:
 *
 *   parent        : parent_user_id — родитель  для student
 *   teacher       : parent_user_id — учитель   для student
 *   methodologist : parent_user_id — методист  для teacher
 *   headteacher   : parent_user_id — завуч      для methodologist
 */
enum RelationType: string
{
    case Parent = 'parent';                 // parent_user_id — родитель   для student
    case Teacher = 'teacher';               // parent_user_id — учитель    для student
    case Methodologist = 'methodologist';   // parent_user_id — методист   для teacher
    case Headteacher = 'headteacher';       // parent_user_id — завуч      для methodologist

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
