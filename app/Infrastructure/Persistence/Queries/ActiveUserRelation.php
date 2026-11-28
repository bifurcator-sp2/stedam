<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Условия «действующей» связи графа user_relations.
 *
 * Связь действует, если:
 *   - уже началась: active_from IS NULL или active_from <= now();
 *   - ещё не закончилась: active_to IS NULL или active_to > now().
 *
 * Формула зафиксирована в одном месте: иначе scope прохождений (Learn),
 * контекст курсов (Course) и счётчики профиля (Community) начнут расходиться
 * на граничных датах.
 */
final class ActiveUserRelation
{
    /**
     * Оба условия активности (началась и не закончилась).
     *
     * @template TBuilder of QueryBuilder|EloquentBuilder
     * @param TBuilder $query
     * @return TBuilder
     */
    public static function apply(QueryBuilder|EloquentBuilder $query, string $alias = ''): QueryBuilder|EloquentBuilder
    {
        $from = self::column('active_from', $alias);
        $to = self::column('active_to', $alias);

        return $query->where(function (QueryBuilder|EloquentBuilder $q) use ($from, $to): void {
            $q->whereNull($from)
                ->orWhere($from, '<=', now());
        })->where(function (QueryBuilder|EloquentBuilder $q) use ($to): void {
            $q->whereNull($to)
                ->orWhere($to, '>', now());
        });
    }

    /**
     * Только условие «ещё не закончилась» — как читали граф до появления
     * active_from. Нужен читателям, которым важна лишь дата окончания.
     *
     * @template TBuilder of QueryBuilder|EloquentBuilder
     * @param TBuilder $query
     * @return TBuilder
     */
    public static function notEnded(QueryBuilder|EloquentBuilder $query, string $alias = ''): QueryBuilder|EloquentBuilder
    {
        $to = self::column('active_to', $alias);

        return $query->where(function (QueryBuilder|EloquentBuilder $q) use ($to): void {
            $q->whereNull($to)
                ->orWhere($to, '>', now());
        });
    }

    private static function column(string $name, string $alias): string
    {
        return $alias === '' ? $name : "{$alias}.{$name}";
    }
}
