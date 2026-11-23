<?php

declare(strict_types=1);

namespace App\Application\Lessons\DTOs;

use App\Application\Lessons\Enums\ActualLessonsScope;
use App\Domain\Lessons\ValueObjects\FullTextSearchQuery;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * Параметры чтения списка уроков.
 *
 * Scope задаётся отдельно (владелец / только опубликованные) — здесь
 * только то, что пришло из запроса:
 *   — taxonomyId   — узел дерева; список расширяется потомками в инфраструктуре;
 *   — search       — уже нормализованный поисковый запрос (null — поиска нет);
 *   — ownerId      — ограничение «только мои» (для личного кабинета);
 *   — onlyPublished— ограничение «только опубликованные» (для каталога);
 *   — orderBy      — колонка сортировки (по умолчанию — по id, от новых);
 *   — scope        — срез «актуальных уроков» по learns пользователя
 *                    (null — без фильтра); сортировка задаётся срезом;
 *   — viewerId     — пользователь, чьи learns учитывает срез.
 */
final readonly class LessonListFilter
{
    public function __construct(
        public ?TaxonomyId $taxonomyId = null,
        public ?FullTextSearchQuery $search = null,
        public ?UserId $ownerId = null,
        public bool $onlyPublished = false,
        public string $orderBy = 'id',
        public ?ActualLessonsScope $scope = null,
        public ?UserId $viewerId = null,
    ) {}

    public static function default(): self
    {
        return new self();
    }
}
