<?php

declare(strict_types=1);

namespace App\Application\Course\Ports;

use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Порт интеграции с Learn (§12.4).
 *
 * Два направления:
 *  - запись: назначение/снятие курса → создать/удалить прохождения уроков;
 *  - чтение: оценки и статусы прохождений → пересчёт курса.
 *
 * Курсы никогда не вызывают Learn\Actions напрямую: только этот порт.
 */
interface LearnPortInterface
{
    /**
     * Создать назначение (прохождение) урока ученику.
     *
     * Идемпотентно: если активное прохождение уже есть — вернуть его id.
     */
    public function enrollLesson(UserId $studentId, LessonId $lessonId): int;

    /**
     * Удалить не начатое прохождение (status ∈ {created, scheduled}).
     * Возвращает true, если удалено. Начатые не трогает (§17.2).
     */
    public function deleteUnstarted(UserId $studentId, LessonId $lessonId): bool;

    /**
     * Оценка прохождения урока (0..1) или null, если урока ещё не было
     * в прохождении. Уже нормализовано к шкале курсов.
     */
    public function gradeOf(UserId $studentId, LessonId $lessonId): ?float;

    /**
     * Оценки пачки уроков: [lessonId => grade|null].
     *
     * @param list<LessonId> $lessonIds
     * @return array<int, float|null>
     */
    public function gradesOf(UserId $studentId, array $lessonIds): array;

    /**
     * Есть ли у ученика оценённое прохождение урока (status = graded).
     */
    public function isGraded(UserId $studentId, LessonId $lessonId): bool;
}
