<?php

declare(strict_types=1);

namespace App\Application\Community\Ports;

use App\Application\Community\DTOs\UserListFilter;
use App\Application\Direction;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Community\ValueObjects\RelationType;
use App\Domain\Community\ValueObjects\UserSummary;

/**
 * Read-side контекста Community: агрегаты по графу user_relations.
 *
 * Реализация прячет физику чтения (таблица, активность связи, индексы);
 * Application получает готовые числа и сам решает, что показывать.
 *
 * «Действующая связь» во всех методах трактуется одинаково с остальными
 * читателями графа (EloquentContextResolver, EloquentCourseContextResolver):
 * active_to IS NULL или active_to > now().
 */
interface RelationsQueryInterface
{
    /**
     * Сколько действующих связей у пользователя по каждому типу роли
     * в заданном направлении.
     *
     * Один запрос GROUP BY relation; типы без связей в результат не
     * попадают — дополнение нулями это забота вызывающего.
     *
     *   Outgoing: parent_user_id = userId  — «сколько у меня ...»
     *   Incoming: child_user_id  = userId  — «скольким я ...»
     *
     * @return array<string, int> RelationType::value => count
     */
    public function countsByType(int $userId, Direction $direction): array;

    /**
     * Есть ли у пользователя ХОТЬ ОДНА действующая связь заданного типа
     * в заданном направлении. Нужен для заполнения флага available.
     */
    public function hasType(int $userId, RelationType $type, Direction $direction): bool;

    /**
     * Постраничный список пользователей по фильтру Community (GET /users).
     *
     * Если relation и counterpart_role заданы ПАРОЙ — возвращаются
     * уникальные пользователи на другом конце ДЕЙСТВУЮЩЕЙ связи текущего
     * пользователя этого типа: направление участия, тип связи и роль на
     * другом конце ребра (teacher + Outgoing + student — «мои ученики»).
     * Если пара не задана (в том числе пришёл только один из параметров) —
     * читаются пользователи напрямую по search и ролям.
     *
     * roles в фильтре — пользователь обязан обладать ВСЕМИ ролями
     * одновременно; фильтр по связям и роли можно комбинировать
     * («мои ученики, имеющие роль parent»).
     *
     * Администраторы в выдачу не попадают (как и в общем списке /users).
     *
     * @return PaginatedResult<UserSummary>
     */
    public function paginateCounterparts(int $userId, UserListFilter $filter, int $page, int $perPage): PaginatedResult;
}
