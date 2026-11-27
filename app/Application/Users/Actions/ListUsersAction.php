<?php

declare(strict_types=1);

namespace App\Application\Users\Actions;

use App\Application\Shared\Pagination\PaginatedResult;
use App\Application\Users\DTOs\UserListFilter;
use App\Application\Users\Ports\UsersQueryInterface;

/**
 * Чтение списка пользователей по «сырым» параметрам запроса.
 *
 * Ответственность Action:
 *   — нормализовать `search`: сначала попытка привести к int — при успехе
 *     поиск идёт исключительно по users.id;
 *   — привести `roles` к списку имён строк;
 *   — делегировать в read-порт.
 *
 * SQL/PostgreSQL-детали сюда не проникают — они в реализации порта.
 */
final readonly class ListUsersAction
{
    /** Минимальная длина подстроки для текстового поиска (вхождения). */
    private const MIN_SEARCH_CHARS = 3;

    public function __construct(
        private UsersQueryInterface $users,
    ) {}

    /**
     * @param array<string, mixed> $input query-параметры запроса
     * @return PaginatedResult<\App\Models\User>
     */
    public function paginate(array $input, int $page, int $perPage): PaginatedResult
    {
        return $this->users->paginate(self::buildFilter($input), $page, $perPage);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function buildFilter(array $input): UserListFilter
    {
        [$search, $searchById] = self::buildSearch($input['search'] ?? null);

        return new UserListFilter(
            search: $search,
            searchById: $searchById,
            roleNames: self::buildRoles($input['roles'] ?? null),
        );
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

        // Сначала — попытка целиком привести ввод к int (успех → поиск по id).
        if (filter_var($search, FILTER_VALIDATE_INT) !== false) {
            return [(string) (int) $search, true];
        }

        // Короткий ввод не ищем: подстрока от 3 символов.
        if (mb_strlen($search) < self::MIN_SEARCH_CHARS) {
            return [null, false];
        }

        return [$search, false];
    }

    /**
     * roles=['teacher','student'] (или строка "teacher,student") → имена строк.
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
