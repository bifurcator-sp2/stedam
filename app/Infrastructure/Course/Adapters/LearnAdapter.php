<?php

declare(strict_types=1);

namespace App\Infrastructure\Course\Adapters;

use App\Application\Course\Ports\LearnPortInterface;
use App\Domain\Course\ValueObjects\LessonId as CourseLessonId;
use App\Domain\Course\ValueObjects\UserId as CourseUserId;
use App\Domain\Learn\Enums\LearnStatus;
use App\Models\Learn as LearnModel;
use Illuminate\Support\Facades\Log;

/**
 * Адаптер курсов → Learn.
 *
 * ВАЖНО: шкалы разные!
 *  - Learn\Grade: 0..100
 *  - Course\CourseGrade: 0..1
 * Нормализация ровно здесь, на границе: наружу всегда 0..1.
 *
 * Обратная сторона (создание прохождений) работает с Learn-таблицей
 * напрямую, без Learn\Actions: чтобы AssignCourseAction не тянул за собой
 * SnapshotResolver, QuestionsBuilder и прочие зависимости старта урока.
 * Создаём именно «отложенное прохождение» (LearnStatus::Created), как
 * Learn::assign() — материал без даты.
 */
final readonly class LearnAdapter implements LearnPortInterface
{
    public function enrollLesson(CourseUserId $studentId, CourseLessonId $lessonId): int
    {
        $existingId = $this->activeLearnId($studentId, $lessonId);

        if ($existingId !== null) {
            return $existingId;
        }

        $model = new LearnModel();
        $model->user_id = $studentId->value;
        $model->lesson_id = $lessonId->value;
        $model->status = LearnStatus::Created->value;
        $model->answers = [];
        $model->questions = [];
        $model->status_date = now();
        $model->created_at = now();
        $model->save();

        return (int) $model->id;
    }

    public function deleteUnstarted(CourseUserId $studentId, CourseLessonId $lessonId): bool
    {
        // Начатые (in_progress) и завершённые (done/graded) не трогаем:
        // у ученика могла остаться сданная работа. started_at IS NULL —
        // страховка для created/scheduled, которые ученик уже открыл.
        $deleted = LearnModel::query()
            ->where('user_id', $studentId->value)
            ->where('lesson_id', $lessonId->value)
            ->whereIn('status', [LearnStatus::Created->value, LearnStatus::Scheduled->value])
            ->whereNull('started_at')
            ->delete();

        return $deleted > 0;
    }

    public function gradeOf(CourseUserId $studentId, CourseLessonId $lessonId): ?float
    {
        $grade = LearnModel::query()
            ->where('user_id', $studentId->value)
            ->where('lesson_id', $lessonId->value)
            ->where('status', LearnStatus::Graded->value)
            ->orderByDesc('complete_date')
            ->value('score');

        return $grade === null ? null : $this->normalize((float) $grade);
    }

    public function gradesOf(CourseUserId $studentId, array $lessonIds): array
    {
        $ids = array_map(static fn (CourseLessonId $id): int => $id->value, $lessonIds);

        if ($ids === []) {
            return [];
        }

        // Одна строка на урок: если по уроку несколько прохождений — берём
        // последнее оценённое.
        $rows = LearnModel::query()
            ->where('user_id', $studentId->value)
            ->whereIn('lesson_id', $ids)
            ->where('status', LearnStatus::Graded->value)
            ->orderBy('complete_date')
            ->get(['lesson_id', 'score']);

        $result = array_fill_keys($ids, null);

        foreach ($rows as $row) {
            $lessonId = (int) $row->lesson_id;
            $result[$lessonId] = $this->normalize((float) $row->score);
        }

        return $result;
    }

    public function isGraded(CourseUserId $studentId, CourseLessonId $lessonId): bool
    {
        return LearnModel::query()
            ->where('user_id', $studentId->value)
            ->where('lesson_id', $lessonId->value)
            ->where('status', LearnStatus::Graded->value)
            ->exists();
    }

    /**
     * 0..100 → 0..1 с защитой от мусора в БД.
     */
    private function normalize(float $score): float
    {
        $normalized = $score / 100.0;

        if ($normalized < 0.0 || $normalized > 1.0) {
            Log::warning('Learn score out of range', ['score' => $score]);

            return max(0.0, min(1.0, $normalized));
        }

        return $normalized;
    }

    private function activeLearnId(CourseUserId $studentId, CourseLessonId $lessonId): ?int
    {
        $id = LearnModel::query()
            ->where('user_id', $studentId->value)
            ->where('lesson_id', $lessonId->value)
            ->whereNotIn('status', [LearnStatus::Done->value, LearnStatus::Graded->value])
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
