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
     * Код типа урока (lessons.type) по каждому уроку пачки.
     *
     * Тип хранится в контексте Lessons строковым кодом справочника
     * (`class_work`, `home_work`, ...), поэтому курс знает только код —
     * id типа ему не нужен.
     *
     * Урок существует, но тип не задан — тоже null.
     *
     * @param list<LessonId> $lessonIds
     * @return array<int, string|null> lessonId => code
     */
    public function typeCodesOf(array $lessonIds): array;

    /**
     * Карточка урока для состава курса (HTTP-представление).
     *
     * Отдаётся то, что нужно фронту предметного редактора: код типа урока
     * (`lessons.type`), название, описание и сложность. Остальное — забота
     * контекста Lessons.
     *
     * Урок существует, но поле не задано — соответствующее значение null.
     * Урок не найден вовсе — его id в ответ не входит.
     *
     * @param list<LessonId> $lessonIds
     * @return array<int, array{type: string|null, title: string|null, description: string|null, complexity: float|null}>
     */
    public function summariesOf(array $lessonIds): array;

    /**
     * Базовый разрыв типа урока (lesson_types.time_gap, целые часы).
     *
     * @param list<string> $codes
     * @return array<string, int> code => часы; отсутствующий код в ответ не входит
     */
    public function timeGapsByTypeCode(array $codes): array;

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
