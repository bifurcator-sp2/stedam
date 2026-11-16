<?php

declare(strict_types=1);

namespace App\Application\Lessons\Ports;

use App\Application\Lessons\DTOs\LessonListFilter;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Models\Lesson;

/**
 * Read-side контекста Lessons: списки уроков и полнотекстовый поиск.
 *
 * Отделён от write-порта LessonRepositoryInterface: репозиторий агрегата
 * занимается сохранением, чтение каталога — отдельная ответственность.
 *
 * Реализация прячет физику чтения: PostgreSQL-полнотекст
 * (to_tsvector/to_tsquery + GIN-индекс idx_lessons_fulltext),
 * расширение таксономии по дереву, сортировку.
 */
interface LessonsQueryInterface
{
    /**
     * Постраничный список с фильтрами (таксономия, поиск, владелец, публикация).
     *
     * Элементы — Eloquent-модели Lesson: HTTP-слой отдаёт их через
     * LessonListResource (as-is, как и раньше).
     *
     * @return PaginatedResult<Lesson>
     */
    public function paginate(LessonListFilter $filter, int $page, int $perPage): PaginatedResult;

    /**
     * То же без пагинации — для небольших выборок («актуальные уроки»).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Lesson>
     */
    public function all(LessonListFilter $filter, ?int $limit = null): \Illuminate\Database\Eloquent\Collection;
}
