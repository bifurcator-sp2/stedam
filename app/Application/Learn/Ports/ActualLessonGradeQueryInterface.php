<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\ValueObjects\UserId;

/**
 * Порт: актуальная (последняя) оценка по уроку для текущего пользователя.
 *
 * Нужен для среза «grades» списка актуальных уроков: в ответе урока
 * отдаётся последняя learn_grades строка по learns пользователя.
 * Чтение — пачкой по lesson_id (без N+1).
 */
interface ActualLessonGradeQueryInterface
{
    /**
     * @param list<int> $lessonIds
     * @return array<int, GradeSummary> ключ — lesson_id
     */
    public function latestByUser(UserId $userId, array $lessonIds): array;
}
