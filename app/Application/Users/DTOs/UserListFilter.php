<?php

declare(strict_types=1);

namespace App\Application\Users\DTOs;

/**
 * Параметры чтения списка пользователей (GET /users).
 *
 *   — search      — нормализованная строка поиска (null — поиска нет);
 *   — searchById  — введённое значение разобрано как целое число:
 *                   поиск идёт ТОЛЬКО по users.id (и по email/ФИО не идёт);
 *   — roleNames   — пользователь обязан обладать ВСЕМИ перечисленными ролями
 *                   (пустой список — фильтра по ролям нет).
 */
final readonly class UserListFilter
{
    /**
     * @param list<string> $roleNames
     */
    public function __construct(
        public ?string $search = null,
        public bool $searchById = false,
        public array $roleNames = [],
    ) {}

    public static function default(): self
    {
        return new self();
    }
}
