<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Application\Shared\Pagination\PaginatedResult;

/**
 * Чтение списка учеников учителя с агрегатами по их прохождениям.
 */
interface TeacherStudentsQueryInterface
{
    /**
     * @param list<int> $studentIds ученики (уже отфильтрованный scope, без смотрящего)
     * @return PaginatedResult<object> элементы — DTO StudentSummary
     */
    public function paginate(
        array $studentIds,
        ?string $search,
        int $page,
        int $perPage,
    ): PaginatedResult;
}
