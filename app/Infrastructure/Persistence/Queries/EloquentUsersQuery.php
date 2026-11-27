<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries;

use App\Application\Shared\Pagination\PaginatedResult;
use App\Application\Users\DTOs\UserListFilter;
use App\Application\Users\Ports\UsersQueryInterface;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Чтение списка пользователей на PostgreSQL.
 *
 * Поиск по ФИО/email — LIKE с подстрокой (`%search%`), а не полнотекстом
 * и не поиском по словам: нужны вхождения строки начиная от 3 символов.
 *
 * Роли (spatie/permission): пользователь должен обладать ВСЕМИ ролями из
 * фильтра — по одной проверке EXISTS на каждое имя.
 */
final class EloquentUsersQuery implements UsersQueryInterface
{
    public function paginate(UserListFilter $filter, int $page, int $perPage): PaginatedResult
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $query = $this->build($filter);

        // Эквивалент simplePaginate: без COUNT(*), берём perPage+1 строку,
        // чтобы узнать, есть ли следующая страница.
        $rows = $query->forPage($page, $perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $items = $rows->take($perPage)->values()->all();

        $seen = ($page - 1) * $perPage + count($items);

        return new PaginatedResult(
            items: $items,
            total: $seen + ($hasMore ? 1 : 0),
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @return Builder<User>
     */
    private function build(UserListFilter $filter): Builder
    {
        $query = User::query()
            ->with(['userData.country.translations', 'roles']);

        // Администраторы в выдачу списка не попадают.
        $query->whereDoesntHave('roles', fn (Builder $q) => $q->where('name', 'admin'));

        if ($filter->search !== null) {
            $search = $filter->search;

            if ($filter->searchById) {
                // Ввод разобран как число: ищем исключительно по users.id.
                $query->where('users.id', (int) $search);
            } else {
                $like = self::escapeLike(mb_strtolower($search));

                // lower(col) LIKE — выражение совпадает с GIN(gin_trgm_ops)-
                // индексами (lower) из миграции add_trigram_indexes_for_user_search,
                // регистронезависимость + использование индекса.
                $query->where(function (Builder $q) use ($like) {
                    $q->whereRaw('lower(users.email) like ?', ["%{$like}%"])
                        ->orWhereHas('userData', function (Builder $data) use ($like) {
                            $data->whereRaw('lower(user_data.first_name) like ?', ["%{$like}%"])
                                ->orWhereRaw('lower(user_data.last_name) like ?', ["%{$like}%"])
                                ->orWhereRaw('lower(user_data.middle_name) like ?', ["%{$like}%"]);
                        });
                });
            }
        }

        // Одновременное наличие всех указанных ролей.
        foreach ($filter->roleNames as $roleName) {
            $query->whereHas('roles', fn (Builder $q) => $q->where('name', $roleName));
        }

        return $query->orderByDesc('users.id');
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
