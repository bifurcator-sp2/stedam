<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries;

use App\Application\Learn\Ports\ActualLessonScopeBuilderInterface;
use App\Application\Lessons\Enums\ActualLessonsScope;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\UserId;
use Illuminate\Support\Facades\DB;

/**
 * Определяет срез «актуальных уроков» по learns пользователя.
 *
 * Один набор запросов на пачку уроков (без N+1): для каждого среза
 * собирается множество lesson_id, затем срезы «накладываются» по
 * приоритету grades → continue → assigned → unassigned.
 */
final class EloquentActualLessonScopeBuilder implements ActualLessonScopeBuilderInterface
{
    public function build(UserId $userId, array $lessonIds): array
    {
        $lessonIds = array_values(array_unique(array_map('intval', $lessonIds)));
        if ($lessonIds === []) {
            return [];
        }

        $grades = $this->lessonIdsWithGrades($userId->value, $lessonIds);
        $inProgress = $this->lessonIdsWithLearnStatus($userId->value, $lessonIds, LearnStatus::InProgress);
        $created = $this->lessonIdsWithLearnStatus($userId->value, $lessonIds, LearnStatus::Created);
        $touched = $this->lessonIdsTouchedByUser($userId->value, $lessonIds);

        $result = [];
        foreach ($lessonIds as $lessonId) {
            $result[$lessonId] = match (true) {
                isset($grades[$lessonId])     => ActualLessonsScope::Grades,
                isset($inProgress[$lessonId]) => ActualLessonsScope::Continue,
                isset($created[$lessonId])    => ActualLessonsScope::Assigned,
                !isset($touched[$lessonId])   => ActualLessonsScope::Unassigned,
                default                       => null,
            };

            if ($result[$lessonId] === null) {
                unset($result[$lessonId]);
            }
        }

        return $result;
    }

    /**
     * @param list<int> $lessonIds
     * @return array<int, true>
     */
    private function lessonIdsWithGrades(int $userId, array $lessonIds): array
    {
        return DB::table('learns')
            ->join('learn_grades', 'learn_grades.learn_id', '=', 'learns.id')
            ->where('learns.user_id', $userId)
            ->whereIn('learns.lesson_id', $lessonIds)
            ->distinct()
            ->pluck('learns.lesson_id')
            ->mapWithKeys(static fn (int $id): array => [$id => true])
            ->all();
    }

    /**
     * @param list<int> $lessonIds
     * @return array<int, true>
     */
    private function lessonIdsWithLearnStatus(int $userId, array $lessonIds, LearnStatus $status): array
    {
        return DB::table('learns')
            ->where('user_id', $userId)
            ->where('status', $status->value)
            ->whereIn('lesson_id', $lessonIds)
            ->distinct()
            ->pluck('lesson_id')
            ->mapWithKeys(static fn (int $id): array => [$id => true])
            ->all();
    }

    /**
     * @param list<int> $lessonIds
     * @return array<int, true>
     */
    private function lessonIdsTouchedByUser(int $userId, array $lessonIds): array
    {
        return DB::table('learns')
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $lessonIds)
            ->distinct()
            ->pluck('lesson_id')
            ->mapWithKeys(static fn (int $id): array => [$id => true])
            ->all();
    }
}
