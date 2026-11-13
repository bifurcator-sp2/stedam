<?php

declare(strict_types=1);

namespace App\Infrastructure\Course\Persistence;

use App\Application\Course\Ports\CourseAssignmentRepositoryInterface;
use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\ValueObjects\CourseAssignmentId;
use App\Domain\Course\ValueObjects\CourseCompletion;
use App\Domain\Course\ValueObjects\CourseGrade;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;
use App\Models\CourseAssignment as AssignmentModel;
use DateTimeImmutable;

/**
 * Eloquent-репозиторий назначений курса.
 */
final readonly class EloquentCourseAssignmentRepository implements CourseAssignmentRepositoryInterface
{
    public function findById(CourseAssignmentId $id): ?CourseAssignment
    {
        $model = AssignmentModel::query()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    public function findByCourseAndStudent(CourseId $courseId, UserId $studentId): ?CourseAssignment
    {
        $model = AssignmentModel::query()
            ->where('course_id', $courseId->value)
            ->where('student_id', $studentId->value)
            ->first();

        return $model === null ? null : $this->toDomain($model);
    }

    public function save(CourseAssignment $assignment): CourseAssignment
    {
        $id = $assignment->id();

        $model = $id === null
            ? new AssignmentModel()
            : AssignmentModel::query()->findOrFail($id->value);

        $model->course_id = $assignment->courseId()->value;
        $model->student_id = $assignment->studentId()->value;
        $model->assigned_by = $assignment->assignedBy()->value;
        $model->completion = $assignment->completion()->value;
        $model->grade = $assignment->grade()->value;

        if (!$model->exists) {
            $model->created_at = $assignment->createdAt();
        }

        $model->save();

        return $this->toDomain($model);
    }

    public function delete(CourseAssignmentId $id): void
    {
        AssignmentModel::query()->whereKey($id->value)->delete();
    }

    public function listByCourse(CourseId $courseId): array
    {
        return AssignmentModel::query()
            ->where('course_id', $courseId->value)
            ->get()
            ->map(fn (AssignmentModel $model) => $this->toDomain($model))
            ->all();
    }

    public function listByStudent(UserId $studentId): array
    {
        return AssignmentModel::query()
            ->where('student_id', $studentId->value)
            ->get()
            ->map(fn (AssignmentModel $model) => $this->toDomain($model))
            ->all();
    }

    public function exists(CourseId $courseId, UserId $studentId): bool
    {
        return AssignmentModel::query()
            ->where('course_id', $courseId->value)
            ->where('student_id', $studentId->value)
            ->exists();
    }

    private function toDomain(AssignmentModel $model): CourseAssignment
    {
        $createdAt = $model->created_at;

        return CourseAssignment::restore(
            id: new CourseAssignmentId((int) $model->id),
            courseId: new CourseId((int) $model->course_id),
            studentId: new UserId((int) $model->student_id),
            assignedBy: new UserId((int) $model->assigned_by),
            completion: new CourseCompletion((float) $model->completion),
            grade: new CourseGrade((float) $model->grade),
            createdAt: $createdAt === null
                ? new DateTimeImmutable()
                : DateTimeImmutable::createFromInterface($createdAt),
        );
    }
}
