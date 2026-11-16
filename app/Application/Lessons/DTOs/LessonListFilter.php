<?php

declare(strict_types=1);

namespace App\Application\Lessons\DTOs;

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
 *   — onlyPublished— ограничение «только опубликованные» (для каталога).
 */
final readonly class LessonListFilter
{
    public function __construct(
        public ?TaxonomyId $taxonomyId = null,
        public ?FullTextSearchQuery $search = null,
        public ?UserId $ownerId = null,
        public bool $onlyPublished = false,
    ) {}

    public static function default(): self
    {
        return new self();
    }
}
