<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries;

use App\Application\Lessons\DTOs\LessonListFilter;
use App\Application\Lessons\Ports\LessonsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Models\Lesson;
use App\Models\Taxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Чтение списка уроков на PostgreSQL.
 *
 * Полнотекстовый поиск опирается на индекс-выражение idx_lessons_fulltext (GIN):
 *   to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(description, ''))
 * поэтому выражение в whereRaw обязано быть БУКВАЛЬНО таким же — иначе
 * планировщик не использует индекс. Построение tsquery (вырезание
 * операторов, prefix-AND) — в домене (FullTextSearchQuery::toTsQuery);
 * здесь только его применение к SQL-выражению.
 */
final class EloquentLessonsQuery implements LessonsQueryInterface
{
    /** Дублирует выражение индекса idx_lessons_fulltext (миграция create_all_tables_pgsql). */
    private const TS_VECTOR_SQL = "to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(description, ''))";

    public function paginate(LessonListFilter $filter, int $page, int $perPage): PaginatedResult
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $query = $this->build($filter);

        // Эквивалент simplePaginate, но через read-порт: без COUNT(*),
        // который на full-text дорог. Берём perPage+1 строку, чтобы узнать,
        // есть ли следующая страница.
        $rows = $query->forPage($page, $perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $items = $rows->take($perPage)->values()->all();

        // total для simple-пагинации недостоверен: отдаём нижнюю границу,
        // как и раньше (hasMorePages считается от неё).
        $seen = ($page - 1) * $perPage + count($items);

        return new PaginatedResult(
            items: $items,
            total: $seen + ($hasMore ? 1 : 0),
            page: $page,
            perPage: $perPage,
        );
    }

    public function all(LessonListFilter $filter, ?int $limit = null): Collection
    {
        $query = $this->build($filter);

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        return $query->get();
    }

    /**
     * @return Builder<Lesson>
     */
    private function build(LessonListFilter $filter): Builder
    {
        $query = Lesson::query()
            ->withCount('blocks')
            ->with(['taxonomies:id']);

        if ($filter->ownerId !== null) {
            $query->where('user_id', $filter->ownerId->value);
        }

        if ($filter->onlyPublished) {
            $query->where('published', true);
        }

        if ($filter->taxonomyId !== null) {
            $taxonomy = Taxonomy::query()->find($filter->taxonomyId->value);

            if ($taxonomy === null) {
                // Несуществующий узел — заведомо пустой результат.
                $query->whereRaw('1 = 0');
            } else {
                $taxIds = $taxonomy->descendantsAndSelf()->pluck('id')->all();
                $query->whereHas('taxonomies', fn (Builder $q) => $q->whereIn('taxonomies.id', $taxIds));
            }
        }

        if ($filter->search !== null) {
            $query->whereRaw(
                self::TS_VECTOR_SQL." @@ to_tsquery('simple', ?)",
                [$filter->search->toTsQuery()],
            );
        }

        return $query->orderByDesc('id');
    }
}
