<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статус обработки оценки Прохождения.
 *
 * Жизненный цикл воркера предварительной оценки:
 *   pending → pre_grading → pre_graded
 *   pending → pre_grading → failed
 * Вручную: pre_graded → graded.
 *
 * pre_grading — интермедиат на время атомарного claim, отделён от
 * in_progress (тот означает ручную оценку преподавателем в домене).
 */
enum LearnGradeStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case PreGrading = 'pre_grading';
    case PreGraded  = 'pre_graded';
    case Graded     = 'graded';
    case Failed     = 'failed';

    /**
     * Список значений для CHECK/ENUM в БД.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
