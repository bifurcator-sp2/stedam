<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Lessons\DTOs\LessonListFilter;
use App\Application\Lessons\Ports\LessonsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Lessons\ValueObjects\FullTextSearchQuery;

/**
 * «Актуальные уроки»: постраничный список опубликованных уроков.
 *
 * Отличие от каталога (ListLessonsAction):
 *   — не учитывается владелец (user_id) и таксономия (tax) — только поиск;
 *   — сортировка по updated_at: недавно обновлённые — вверху.
 */
final readonly class GetActualLessonsAction
{
    public function __construct(
        private LessonsQueryInterface $lessons,
    ) {}

    /**
     * @param array<string, mixed> $input query-параметры запроса (используется только search)
     * @return PaginatedResult<\App\Models\Lesson>
     */
    public function paginate(array $input, int $page, int $perPage): PaginatedResult
    {
        return $this->lessons->paginate(
            new LessonListFilter(
                search: self::buildSearch($input['search'] ?? null),
                onlyPublished: true,
                orderBy: 'updated_at',
            ),
            $page,
            $perPage,
        );
    }

    /**
     * Запрос без значимых символов («+++», «   ») — это «поиска нет»,
     * а не ошибка: список отдаётся без фильтра по тексту.
     */
    private static function buildSearch(mixed $raw): ?FullTextSearchQuery
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return FullTextSearchQuery::fromUserInput($raw);
        } catch (\DomainException) {
            return null;
        }
    }
}
