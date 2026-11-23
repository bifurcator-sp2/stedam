<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Lessons\DTOs\LessonListFilter;
use App\Application\Lessons\Enums\ActualLessonsScope;
use App\Application\Lessons\Ports\LessonsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Lessons\ValueObjects\FullTextSearchQuery;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * «Актуальные уроки»: постраничный список опубликованных уроков.
 *
 * Отличие от каталога (ListLessonsAction):
 *   — не учитывается таксономия (tax) — только поиск;
 *   — по умолчанию сортировка по updated_at (недавно обновлённые — вверху);
 *   — параметр filter=unassigned|assigned|continue|grades сужает список
 *     по learns текущего пользователя и задаёт свою сортировку
 *     (см. ActualLessonsScope). Без filter поведение не меняется.
 */
final readonly class GetActualLessonsAction
{
    public function __construct(
        private LessonsQueryInterface $lessons,
    ) {}

    /**
     * @param array<string, mixed> $input query-параметры запроса (search, filter)
     * @return PaginatedResult<\App\Models\Lesson>
     */
    public function paginate(array $input, int $page, int $perPage, int $viewerId): PaginatedResult
    {
        $scope = ActualLessonsScope::tryFrom((string) ($input['filter'] ?? ''));

        return $this->lessons->paginate(
            new LessonListFilter(
                search: self::buildSearch($input['search'] ?? null),
                onlyPublished: true,
                orderBy: 'updated_at',
                // Без filter — прежнее поведение: только поиск и updated_at.
                scope: $scope,
                viewerId: $scope !== null ? new UserId($viewerId) : null,
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
