<?php

declare(strict_types=1);

namespace App\Application\Lessons\Actions;

use App\Application\Lessons\DTOs\LessonListFilter;
use App\Application\Lessons\Ports\LessonsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Lessons\ValueObjects\FullTextSearchQuery;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * Чтение списка уроков по «сырым» параметрам запроса.
 *
 * Ответственность Action:
 *   — превратить строки запроса в доменные VO (TaxonomyId, FullTextSearchQuery, UserId);
 *   — делегировать в read-порт.
 *
 * SQL/PostgreSQL-детали сюда не проникают — они в реализации порта.
 */
final readonly class ListLessonsAction
{
    public function __construct(
        private LessonsQueryInterface $lessons,
    ) {}

    /**
     * @param array<string, mixed> $input query-параметры запроса
     * @return PaginatedResult<\App\Models\Lesson>
     */
    public function paginate(array $input, int $page, int $perPage): PaginatedResult
    {
        return $this->lessons->paginate(self::buildFilter($input), $page, $perPage);
    }

    /**
     * @param array<string, mixed> $input query-параметры запроса
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lesson>
     */
    public function all(array $input, ?int $limit = null): \Illuminate\Database\Eloquent\Collection
    {
        return $this->lessons->all(self::buildFilter($input), $limit);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function buildFilter(array $input): LessonListFilter
    {
        $taxId = (int) ($input['tax'] ?? 0);
        $ownerId = (int) ($input['user_id'] ?? 0);

        return new LessonListFilter(
            taxonomyId: $taxId > 0 ? new TaxonomyId($taxId) : null,
            search: self::buildSearch($input['search'] ?? null),
            ownerId: $ownerId > 0 ? new UserId($ownerId) : null,
            onlyPublished: filter_var($input['only_published'] ?? false, FILTER_VALIDATE_BOOL),
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
