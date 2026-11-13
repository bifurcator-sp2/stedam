<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Course\Ports\LearnPortInterface;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Порт Learn в памяти — для тестов Application-слоя курсов.
 *
 * Никаких Eloquent-моделей Learn: только то, что видит курс, — оценки,
 * факт «оценён» и операции зачисления. Оценки хранятся в шкале 0..1,
 * как их отдаёт настоящий адаптер.
 */
final class InMemoryLearnPort implements LearnPortInterface
{
    /** @var array<string, int> "studentId:lessonId" => learnId */
    private array $enrolled = [];

    /** @var array<string, float> "studentId:lessonId" => grade 0..1 */
    private array $grades = [];

    /** @var array<string, bool> "studentId:lessonId" => false (начатое не удаляется) */
    private array $started = [];

    public int $enrollCalls = 0;

    public int $deleteUnstartedCalls = 0;

    private int $nextLearnId = 1;

    public function enrollLesson(UserId $studentId, LessonId $lessonId): int
    {
        $this->enrollCalls++;
        $key = $this->key($studentId, $lessonId);

        return $this->enrolled[$key] ??= $this->nextLearnId++;
    }

    public function deleteUnstarted(UserId $studentId, LessonId $lessonId): bool
    {
        $this->deleteUnstartedCalls++;
        $key = $this->key($studentId, $lessonId);

        // Начатое прохождение не удаляется (§17.2).
        if (($this->started[$key] ?? false) === true) {
            return false;
        }

        unset($this->enrolled[$key], $this->grades[$key]);

        return true;
    }

    public function gradeOf(UserId $studentId, LessonId $lessonId): ?float
    {
        return $this->grades[$this->key($studentId, $lessonId)] ?? null;
    }

    public function gradesOf(UserId $studentId, array $lessonIds): array
    {
        $result = [];

        foreach ($lessonIds as $lessonId) {
            $result[$lessonId->value] = $this->gradeOf($studentId, $lessonId);
        }

        return $result;
    }

    public function isGraded(UserId $studentId, LessonId $lessonId): bool
    {
        return $this->gradeOf($studentId, $lessonId) !== null;
    }

    // === Подготовка сцены в тестах ===

    public function setGrade(UserId $studentId, LessonId $lessonId, float $grade): void
    {
        $this->grades[$this->key($studentId, $lessonId)] = $grade;
    }

    public function markStarted(UserId $studentId, LessonId $lessonId): void
    {
        $this->started[$this->key($studentId, $lessonId)] = true;
    }

    public function isEnrolled(UserId $studentId, LessonId $lessonId): bool
    {
        return isset($this->enrolled[$this->key($studentId, $lessonId)]);
    }

    public function enrolledCount(): int
    {
        return count($this->enrolled);
    }

    private function key(UserId $studentId, LessonId $lessonId): string
    {
        return $studentId->value . ':' . $lessonId->value;
    }
}
