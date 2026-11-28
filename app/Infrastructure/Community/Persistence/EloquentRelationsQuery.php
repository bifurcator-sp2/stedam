<?php

declare(strict_types=1);

namespace App\Infrastructure\Community\Persistence;

use App\Application\Community\DTOs\UserListFilter;
use App\Application\Community\Ports\RelationsQueryInterface;
use App\Application\Direction;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Community\ValueObjects\RelationType;
use App\Domain\Community\ValueObjects\UserSummary;
use App\Infrastructure\Persistence\Queries\ActiveUserRelation;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Чтение агрегатов графа user_relations на PostgreSQL.
 *
 * Оба направления сводятся к одному запросу с GROUP BY relation:
 * индекс user_relations_parent_relation_idx / user_relations_child_relation_idx
 * покрывает выборку, агрегат считается без чтения строк целиком.
 *
 * Активность связи — общий скауп ActiveUserRelation: счётчики в профиле
 * совпадают со scope прохождений и контекстом курсов.
 */
final readonly class EloquentRelationsQuery implements RelationsQueryInterface
{
    public function countsByType(int $userId, Direction $direction): array
    {
        $rows = $this->active($userId, $direction)
            ->select('relation', DB::raw('count(*) as aggregate'))
            ->groupBy('relation')
            ->pluck('aggregate', 'relation');

        $counts = [];
        foreach ($rows as $relation => $aggregate) {
            $counts[(string) $relation] = (int) $aggregate;
        }

        return $counts;
    }

    public function hasType(int $userId, RelationType $type, Direction $direction): bool
    {
        return $this->active($userId, $direction)
            ->where('relation', $type->value)
            ->exists();
    }

    /**
     * Действующие связи пользователя в заданном направлении.
     */
    private function active(int $userId, Direction $direction): Builder
    {
        $column = $direction->isOutgoing() ? 'parent_user_id' : 'child_user_id';

        return ActiveUserRelation::apply(
            DB::table('user_relations')->where($column, $userId),
        );
    }

    /**
     * Люди из связей пользователя.
     *
     * Один запрос с IN (подзапрос по user_relations), поэтому связи
     * фильтруются по индексам parent_user_id/child_user_id + relation,
     * а дедупликация и пагинация — по users.
     *
     * Если relation и counterpart_role заданы парой — выборка берётся из
     * людей на другом конце действующих связей этого типа; иначе читаются
     * пользователи напрямую (с фильтрами search/roles).
     */
    public function paginateCounterparts(int $userId, UserListFilter $filter, int $page, int $perPage): PaginatedResult
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $query = User::query()
            ->with(['userData.country'])
            // Администраторы в списки пользователей не попадают.
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'admin'));

        if ($filter->usesRelations()) {
            [$selfColumn, $otherColumn] = $filter->direction->isOutgoing()
                ? ['parent_user_id', 'child_user_id']
                : ['child_user_id', 'parent_user_id'];

            $edges = ActiveUserRelation::apply(
                DB::table('user_relations')->where($selfColumn, $userId),
            )
                ->where('relation', $filter->relation)
                ->select($otherColumn);

            $this->whereRole($edges, $otherColumn, (string) $filter->counterpartRole);

            $query->whereIn('users.id', $edges);
        }

        $this->applySearch($query, $filter);

        // Человек должен обладать ВСЕМИ указанными ролями.
        foreach ($filter->roleNames as $roleName) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $roleName));
        }

        $rows = $query->orderByDesc('users.id')->forPage($page, $perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $items = $rows->take($perPage)->map(self::toSummary(...))->values()->all();

        $seen = ($page - 1) * $perPage + count($items);

        return new PaginatedResult(
            items: $items,
            total: $seen + ($hasMore ? 1 : 0),
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * Роль человека на другом конце ребра проверяется через его Spatie-роли:
     * `relation` задаёт, кто с какой стороны, а `counterpart_role` — кем
     * должен быть человек в users.roles.
     */
    private function whereRole(Builder $edges, string $otherColumn, string $roleName): void
    {
        $edges->whereIn(
            $otherColumn,
            DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', $roleName)
                ->select('model_has_roles.model_id'),
        );
    }

    /**
     * Поиск по id / email / ФИО — как в общем списке пользователей.
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     */
    private function applySearch($query, UserListFilter $filter): void
    {
        if ($filter->search === null) {
            return;
        }

        if ($filter->searchById) {
            $query->where('users.id', (int) $filter->search);

            return;
        }

        $like = self::escapeLike(mb_strtolower($filter->search));

        $query->where(function ($q) use ($like) {
            $q->whereRaw('lower(users.email) like ?', ["%{$like}%"])
                ->orWhereHas('userData', function ($data) use ($like) {
                    $data->whereRaw('lower(user_data.first_name) like ?', ["%{$like}%"])
                        ->orWhereRaw('lower(user_data.last_name) like ?', ["%{$like}%"])
                        ->orWhereRaw('lower(user_data.middle_name) like ?', ["%{$like}%"]);
                });
        });
    }

    /**
     * @param User $user
     */
    private static function toSummary(User $user): UserSummary
    {
        $data = $user->userData;
        $country = $data?->country;

        return new UserSummary(
            id: (int) $user->id,
            firstName: $data?->first_name,
            lastName: $data?->last_name,
            middleName: $data?->middle_name,
            countryId: $country?->id,
            countryName: $country?->name,
        );
    }

    /**
     * Экранирование спецсимволов LIKE (\, %, _), чтобы «%» в поиске
     * не превращался в шаблон «любая подстрока».
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
