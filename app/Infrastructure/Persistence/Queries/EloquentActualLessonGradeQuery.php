<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries;

use App\Application\Learn\DTOs\GradeSummary;
use App\Application\Learn\Ports\ActualLessonGradeQueryInterface;
use App\Domain\Learn\ValueObjects\UserId;
use Illuminate\Support\Facades\DB;

/**
 * Последняя learn_grades строка по каждому уроку пользователя.
 *
 * «Последняя» — по learn_grades.updated_at (id как tie-breaker).
 * Один запрос на всю пачку уроков: оконная функция row_number()
 * в PostgreSQL-подзапросе по learns пользователя.
 */
final class EloquentActualLessonGradeQuery implements ActualLessonGradeQueryInterface
{
    private const STATUS_GRADED = 'Оценено';
    private const STATUS_PRE_GRADED = 'Предворительно оценено';
    private const STATUS_PENDING = 'Ждет оценки';

    public function latestByUser(UserId $userId, array $lessonIds): array
    {
        $lessonIds = array_values(array_unique(array_map('intval', $lessonIds)));
        if ($lessonIds === []) {
            return [];
        }

        $ranked = DB::table('learn_grades')
            ->join('learns', 'learns.id', '=', 'learn_grades.learn_id')
            ->where('learns.user_id', $userId->value)
            ->whereIn('learns.lesson_id', $lessonIds)
            ->select([
                'learns.lesson_id',
                'learn_grades.grade',
                'learn_grades.pre_grade',
                DB::raw(
                    'row_number() over (partition by learns.lesson_id'
                    .' order by learn_grades.updated_at desc, learn_grades.id desc) as rn'
                ),
            ]);

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', 1)
            ->get(['lesson_id', 'grade', 'pre_grade']);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->lesson_id] = self::summarize(
                $row->grade === null ? null : (float) $row->grade,
                $row->pre_grade === null ? null : (float) $row->pre_grade,
            );
        }

        return $result;
    }

    private static function summarize(?float $grade, ?float $preGrade): GradeSummary
    {
        if ($grade !== null) {
            return new GradeSummary($grade, self::STATUS_GRADED);
        }

        if ($preGrade !== null) {
            return new GradeSummary($preGrade, self::STATUS_PRE_GRADED);
        }

        return new GradeSummary(null, self::STATUS_PENDING);
    }
}
