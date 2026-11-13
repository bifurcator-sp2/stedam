<?php

declare(strict_types=1);

namespace App\Application\Course\Ports;

use App\Domain\Course\ValueObjects\LessonId;

/**
 * Порт чтения уроков из контекста Lessons.
 *
 * Курсы не ходят в таблицу lessons напрямую: им нужны только сложность
 * и принадлежность уроков ветке таксономии (§12.5). Всё остальное —
 * забота Lessons.
 */
interface LessonReaderInterface
{
    /**
     * Сложность урока (0..1) или null, если урок не найден / сложность
     * не задана.
     */
    public function complexityOf(LessonId $lessonId): ?float;

    /**
     * Сложности пачки уроков: [lessonId => complexity|null].
     *
     * @param list<LessonId> $lessonIds
     * @return array<int, float|null>
     */
    public function complexitiesOf(array $lessonIds): array;

    /**
     * Существует ли урок. Нужно для проверки перед addLesson.
     */
    public function exists(LessonId $lessonId): bool;

    /**
     * Ветка таксономии, к которой принадлежит урок (или null).
     * Используется при проверке «курс и его уроки в одной ветке».
     */
    public function taxonomyIdOf(LessonId $lessonId): ?int;

    /**
     * Ветки таксономии пачки уроков: [lessonId => taxonomyId|null].
     *
     * Урок принадлежит одной ветке (lesson_taxonomy.lesson_id уникален),
     * поэтому батч-чтение — просто один SELECT по списку id.
     *
     * @param list<LessonId> $lessonIds
     * @return array<int, int|null>
     */
    public function taxonomyIdsOf(array $lessonIds): array;

    /**
     * Узел таксономии и все его потомки — плоский список id.
     *
     * @return list<int>
     */
    public function taxonomySubtreeIds(int $taxonomyId): array;
}
