<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Application\Lessons\Enums\ActualLessonsScope;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Порт: вычислить срез «актуальных уроков» для конкретного урока.
 *
 * Нужен для того, чтобы отдать в ресурсе урока, к какому срезу относится
 * каждый элемент списка (unassigned / assigned / continue / grades).
 *
 * Правила — те же, что у списка GET /lessons/actual?filter=...:
 * учитываются только learns пользователя; при попадании в несколько
 * срезов приоритет: grades → continue → assigned → unassigned.
 */
interface ActualLessonScopeBuilderInterface
{
    /**
     * @param list<int> $lessonIds
     * @return array<int, ActualLessonsScope> срез по lesson_id
     *         (уроки, по которым срез не определяется, отсутствуют)
     */
    public function build(UserId $userId, array $lessonIds): array;
}
