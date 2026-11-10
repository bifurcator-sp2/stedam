<?php

declare(strict_types=1);

namespace App\Application\Learn\Context;

/**
 * Типы рёбер графа владения/наблюдения (user_relations.relation).
 *
 * Источник правды для строкового значения `relation` в БД.
 * Application-слой; Infrastructure читает значения отсюда.
 */
enum RelationType: string
{
    case Teaches = 'teaches';           // teacher        → student
    case Parents = 'parents';           // parent         → student
    case Supervises = 'supervises';     // methodologist  → teacher
    case Heads = 'heads';               // headteacher    → methodologist

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
