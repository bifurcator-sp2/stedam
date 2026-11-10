<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LessonId;
use DateTimeImmutable;

/**
 * Фильтр для постраничного чтения прохождений.
 *
 * Scope по пользователям задаётся отдельно (ViewerContext) — здесь только
 * параметры, пришедшие из запроса: статусы, урок, диапазон дат, пагинация.
 */
final readonly class LearnListFilter
{
    /**
     * @param list<LearnStatus> $statuses
     */
    public function __construct(
        public array $statuses = [],
        public ?LessonId $lessonId = null,
        public ?DateTimeImmutable $dateFrom = null,
        public ?DateTimeImmutable $dateTo = null,
        public int $page = 1,
        public int $perPage = 20,
        public bool $includeArchived = false,
    ) {}

    public static function default(): self
    {
        return new self();
    }
}
