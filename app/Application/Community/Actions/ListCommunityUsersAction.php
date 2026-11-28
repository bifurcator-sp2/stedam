<?php

declare(strict_types=1);

namespace App\Application\Community\Actions;

use App\Application\Community\DTOs\UserListFilter;
use App\Application\Community\Ports\RelationsQueryInterface;
use App\Application\Direction;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Community\ValueObjects\RelationType;
use App\Domain\Community\ValueObjects\UserId;
use App\Domain\Community\ValueObjects\UserSummary;

/**
 * Люди из связей текущего пользователя — GET /users.
 *
 * Пример: `?search=165&relation=teacher&counterpart_role=student&page=1&per_page=20`
 * → ученики текущего пользователя в его роли учителя.
 *
 * Action нормализует «сырые» query-параметры в UserListFilter и отдаёт
 * выборку порту; SQL-детали живут в инфраструктуре.
 *
 *   — relation        — тип ребра (RelationType::value), например `teacher`;
 *   — counterpart_role — роль человека на другом конце ребра
 *                       (для `teacher` это `student`);
 *   — direction       — сторона участия текущего пользователя
 *                       (`outgoing` по умолчанию, `incoming`);
 *   — roles[]         — роли, которыми человек должен обладать одновременно;
 *   — search          — число → поиск по users.id; строка от 3 символов →
 *                       вхождения по email/ФИО.
 *
 * relation и counterpart_role — одна пара: если пришёл только один из них,
 * фильтр по связям не применяется. Неизвестный код связи (или роли на другом
 * конце ребра) даёт пустой результат: запрос явно просит то, чего в графе
 * не бывает.
 */
final readonly class ListCommunityUsersAction
{
    /** Минимальная длина подстроки для текстового поиска (вхождения). */
    private const MIN_SEARCH_CHARS = 3;

    public function __construct(
        private RelationsQueryInterface $relations,
    ) {}

    /**
     * @param array<string, mixed> $input query-параметры запроса
     * @return PaginatedResult<UserSummary>
     */
    public function paginate(UserId $viewerId, array $input, int $page, int $perPage): PaginatedResult
    {
        $filter = self::buildFilter($input);

        if ($filter->usesRelations() && !self::isKnownRelationPair($filter)) {
            return new PaginatedResult([], 0, $page, $perPage);
        }

        return $this->relations->paginateCounterparts($viewerId->value, $filter, $page, $perPage);
    }

    /**
     * relation и counterpart_role корректны как пара: ребро существует и
     * роль на другом конце — та, которую задаёт тип ребра.
     */
    private static function isKnownRelationPair(UserListFilter $filter): bool
    {
        $type = RelationType::tryFrom((string) $filter->relation);

        return $type !== null && $type->counterpartRole() === $filter->counterpartRole;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function buildFilter(array $input): UserListFilter
    {
        [$search, $searchById] = self::buildSearch($input['search'] ?? null);

        [$relation, $counterpartRole] = self::buildRelationPair(
            self::buildCode($input['relation'] ?? null),
            self::buildCode($input['counterpart_role'] ?? null),
        );

        return new UserListFilter(
            search: $search,
            searchById: $searchById,
            relation: $relation,
            counterpartRole: $counterpartRole,
            direction: Direction::tryFrom((string) ($input['direction'] ?? '')) ?? Direction::Outgoing,
            roleNames: self::buildRoles($input['roles'] ?? null),
        );
    }

    /**
     * relation и counterpart_role работают только парой: одиночный
     * параметр игнорируется.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function buildRelationPair(?string $relation, ?string $counterpartRole): array
    {
        return $relation !== null && $counterpartRole !== null
            ? [$relation, $counterpartRole]
            : [null, null];
    }

    /**
     * Поиск: пустая строка или строка короче 3 символов — «поиска нет».
     * «12» → поиск только по users.id; «ivan» → вхождения по email/ФИО.
     *
     * @return array{0: ?string, 1: bool}
     */
    private static function buildSearch(mixed $raw): array
    {
        if (is_int($raw)) {
            return [(string) max(0, $raw), true];
        }

        if (!is_string($raw)) {
            return [null, false];
        }

        $search = trim($raw);
        if ($search === '') {
            return [null, false];
        }

        if (filter_var($search, FILTER_VALIDATE_INT) !== false) {
            return [(string) (int) $search, true];
        }

        if (mb_strlen($search) < self::MIN_SEARCH_CHARS) {
            return [null, false];
        }

        return [$search, false];
    }

    /**
     * Код связи/роли из query-параметра (строка, trimmed, в нижнем регистре).
     */
    private static function buildCode(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $code = mb_strtolower(trim($raw));

        return $code === '' ? null : $code;
    }

    /**
     * roles=['teacher','student'] (или строка "teacher,student") → имена ролей.
     *
     * @return list<string>
     */
    private static function buildRoles(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($name) => is_string($name) ? trim($name) : '',
            $raw,
        ))));
    }
}
