<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\DTOs;

/**
 * Параметры чтения «моих заявок» (GET /requested-actions).
 *
 *   — initiatorId — автор заявок: всегда берётся из auth(), не из запроса;
 *   — search      — нормализованная строка поиска (null — поиска нет);
 *   — searchById  — ввод разобран как целое число: поиск идёт по
 *                   requested_actions.id ИЛИ по id получателя из
 *                   payload.consumers;
 *   — statuses    — фильтр по статусам агрегата (пустой список — без фильтра);
 *   — aliases     — фильтр по типам заявок (alias обработчика; пустой — без фильтра).
 */
final readonly class RequestedActionListFilter
{
    /**
     * @param list<string> $statuses
     * @param list<string> $aliases
     */
    public function __construct(
        public int $initiatorId,
        public ?string $search = null,
        public bool $searchById = false,
        public array $statuses = [],
        public array $aliases = [],
    ) {}
}
