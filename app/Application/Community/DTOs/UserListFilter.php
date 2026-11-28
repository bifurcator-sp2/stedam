<?php

declare(strict_types=1);

namespace App\Application\Community\DTOs;

use App\Application\Direction;

/**
 * Фильтр списка людей из связей текущего пользователя (GET /users).
 *
 *   — search          — нормализованная строка поиска (null — поиска нет):
 *                       число → поиск по users.id, иначе вхождения по
 *                       email/ФИО от 3 символов;
 *   — searchById      — поиск только по users.id;
 *   — relation        — тип связи (RelationType::value), например `teacher`;
 *   — counterpartRole — роль человека на другом конце ребра
 *                       (например, `student` для «моих учеников»);
 *   — direction       — сторона участия текущего пользователя:
 *                       Outgoing — он на стороне parent_user_id,
 *                       Incoming — на стороне child_user_id;
 *   — roleNames       — роли, которыми человек должен обладать ОДНОВРЕМЕННО
 *                       (комбинация с relation/counterpart_role: например
 *                       «мои ученики, имеющие роль parent»).
 */
final readonly class UserListFilter
{
    /**
     * @param list<string> $roleNames
     */
    public function __construct(
        public ?string $search = null,
        public bool $searchById = false,
        public ?string $relation = null,
        public ?string $counterpartRole = null,
        public Direction $direction = Direction::Outgoing,
        public array $roleNames = [],
    ) {}

    public static function default(): self
    {
        return new self();
    }

    /**
     * Задан ли фильтр по графу связей. relation и counterpart_role
     * работают только парой — одиночный параметр связью не считается.
     */
    public function usesRelations(): bool
    {
        return $this->relation !== null && $this->counterpartRole !== null;
    }
}
