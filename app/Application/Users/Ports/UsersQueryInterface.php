<?php

declare(strict_types=1);

namespace App\Application\Users\Ports;

use App\Application\Shared\Pagination\PaginatedResult;
use App\Application\Users\DTOs\UserListFilter;
use App\Models\User;

/**
 * Read-side контекста Users: списки пользователей с поиском и фильтром по ролям.
 *
 * Реализация прячет физику чтения: PostgreSQL LIKE с подстрокой (вхождения
 * от 3 символов), поиск по users.id / email / user_data.first_name|last_name|
 * middle_name, обязательное одновременное наличие набора ролей (spatie).
 */
interface UsersQueryInterface
{
    /**
     * Постраничный список пользователей.
     *
     * Элементы — Eloquent-модели User с загруженными userData.country и roles;
     * HTTP-слой отдаёт их через UserListResource.
     *
     * @return PaginatedResult<User>
     */
    public function paginate(UserListFilter $filter, int $page, int $perPage): PaginatedResult;
}
