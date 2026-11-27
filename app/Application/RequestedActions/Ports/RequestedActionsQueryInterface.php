<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Ports;

use App\Application\RequestedActions\DTOs\RequestedActionListFilter;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Models\RequestedAction;

/**
 * Read-side контекста RequestedAction: список заявок автора.
 *
 * Реализация прячет физику чтения партиционированной таблицы
 * (LIST(complete) → RANGE(created_at)): обе ветки читаются через родителя,
 * поэтому в выдачу попадают и активные, и завершённые заявки.
 */
interface RequestedActionsQueryInterface
{
    /**
     * Постраничный список заявок инициатора, от самых свежих к старым.
     *
     * Элементы — Eloquent-модели RequestedAction; HTTP-слой отдаёт их через
     * ресурс RequestedActionListResource.
     *
     * @return PaginatedResult<RequestedAction>
     */
    public function paginate(RequestedActionListFilter $filter, int $page, int $perPage): PaginatedResult;
}
