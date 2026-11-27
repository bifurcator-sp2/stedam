<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries;

use App\Application\RequestedActions\DTOs\RequestedActionListFilter;
use App\Application\RequestedActions\Ports\RequestedActionsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Models\RequestedAction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Чтение «моих заявок» на PostgreSQL.
 *
 * Таблица партиционирована LIST(complete) → RANGE(created_at). Запрос идёт
 * через родителя без фильтра `complete`, поэтому в выдачу попадают и активная,
 * и завершённая ветки (2.15 п.23). Порядок — created_at DESC, id DESC:
 * сначала самые свежие, id добивает строки с одинаковой меткой времени.
 *
 * Числовой ввод ищется и по id заявки, и по id получателя из payload.consumers.
 * Нечисловой — по email/ФИО получателей из payload.consumers (подстрока, от
 * 3 символов, id сравниваются как текст). Регистронезависимость — `ILIKE`.
 */
final class EloquentRequestedActionsQuery implements RequestedActionsQueryInterface
{
    /**
     * Получатели, подходящие под поиск: email/ФИО (подстрока).
     *
     * `jsonb_array_elements_text` разворачивает массив id из payload;
     * COALESCE — чтобы пользователь без user_data не выпадал из JOIN.
     * Поиск по id получателя вынесен отдельно (SEARCH_CONSUMER_IDS_SQL).
     */
    private const SEARCH_CONSUMERS_SQL = <<<'SQL'
        EXISTS (
            SELECT 1
              FROM jsonb_array_elements_text(requested_actions.payload -> 'consumers') AS consumer(id)
              LEFT JOIN users  AS cu ON cu.id::text = consumer.id
              LEFT JOIN user_data AS cud ON cud.user_id = cu.id
             WHERE cu.email ILIKE ? ESCAPE '\'
                OR COALESCE(cud.first_name, '') ILIKE ? ESCAPE '\'
                OR COALESCE(cud.last_name, '') ILIKE ? ESCAPE '\'
                OR COALESCE(cud.middle_name, '') ILIKE ? ESCAPE '\'
        )
        SQL;

    /**
     * Получатели с точным id из payload.consumers.
     *
     * `jsonb_array_elements_text` разворачивает массив; id из payload приходит
     * как текст, поэтому сравнение с параметром — текстовое.
     */
    private const SEARCH_CONSUMER_IDS_SQL = <<<'SQL'
        EXISTS (
            SELECT 1
              FROM jsonb_array_elements_text(requested_actions.payload -> 'consumers') AS consumer(id)
             WHERE consumer.id = ?
        )
        SQL;

    public function paginate(RequestedActionListFilter $filter, int $page, int $perPage): PaginatedResult
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
     * @return Builder<RequestedAction>
     */
    private function build(RequestedActionListFilter $filter): Builder
    {
        $query = RequestedAction::query()
            ->where('requested_actions.user_id', $filter->initiatorId);

        if ($filter->search !== null) {
            $search = $filter->search;

            if ($filter->searchById) {
                // Ввод разобран как число: совпадение по id заявки ИЛИ по id
                // получателя из payload.consumers.
                $query->where(function (Builder $q) use ($search): void {
                    $q->where('requested_actions.id', (int) $search)
                        ->orWhereRaw(self::SEARCH_CONSUMER_IDS_SQL, [$search]);
                });
            } else {
                $like = '%' . self::escapeLike($search) . '%';

                $query->whereRaw(
                    self::SEARCH_CONSUMERS_SQL,
                    [$like, $like, $like, $like],
                );
            }
        }

        if ($filter->statuses !== []) {
            $query->whereIn('requested_actions.status', $filter->statuses);
        }

        if ($filter->aliases !== []) {
            $query->whereIn('requested_actions.handler', $filter->aliases);
        }

        return $query
            ->orderByDesc('requested_actions.created_at')
            ->orderByDesc('requested_actions.id');
    }

    /**
     * Экранирование спецсимволов LIKE/ILIKE (\, %, _), чтобы «%» в поиске
     * не превращался в шаблон «любая подстрока».
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
