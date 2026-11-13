<?php

declare(strict_types=1);

namespace App\Domain\Course\Entities;

use App\Domain\Course\Exceptions\CourseAlreadyAssigned;
use App\Domain\Course\ValueObjects\CourseAssignmentId;
use App\Domain\Course\ValueObjects\CourseCompletion;
use App\Domain\Course\ValueObjects\CourseGrade;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;
use DateTimeImmutable;

/**
 * Назначение курса ученику — отдельный агрегат.
 *
 * Отдельный от Course намеренно: назначений много, курс один; разные
 * жизненные циклы и транзакционные границы.
 *
 * completion и grade — производные: их пишет только Application-слой
 * (CourseGradeRecalculator), но инвариант «значение в [0..1]» держит VO.
 */
final readonly class CourseAssignment
{
    private function __construct(
        private ?CourseAssignmentId $id,
        private CourseId $courseId,
        private UserId $studentId,
        private UserId $assignedBy,
        private CourseCompletion $completion,
        private CourseGrade $grade,
        private DateTimeImmutable $createdAt,
    ) {}

    public static function create(
        CourseId $courseId,
        UserId $studentId,
        UserId $assignedBy,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: null,
            courseId: $courseId,
            studentId: $studentId,
            assignedBy: $assignedBy,
            completion: CourseCompletion::zero(),
            grade: CourseGrade::zero(),
            createdAt: $now,
        );
    }

    public static function restore(
        CourseAssignmentId $id,
        CourseId $courseId,
        UserId $studentId,
        UserId $assignedBy,
        CourseCompletion $completion,
        CourseGrade $grade,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id,
            $courseId,
            $studentId,
            $assignedBy,
            $completion,
            $grade,
            $createdAt,
        );
    }

    public function id(): ?CourseAssignmentId
    {
        return $this->id;
    }

    public function courseId(): CourseId
    {
        return $this->courseId;
    }

    public function studentId(): UserId
    {
        return $this->studentId;
    }

    public function assignedBy(): UserId
    {
        return $this->assignedBy;
    }

    public function completion(): CourseCompletion
    {
        return $this->completion;
    }

    public function grade(): CourseGrade
    {
        return $this->grade;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function withId(CourseAssignmentId $id): self
    {
        return new self(
            $id,
            $this->courseId,
            $this->studentId,
            $this->assignedBy,
            $this->completion,
            $this->grade,
            $this->createdAt,
        );
    }

    /**
     * Пересчёт оценок — вызывается Application-слоем; идемпотентно.
     */
    public function updateScores(CourseCompletion $completion, CourseGrade $grade): self
    {
        return new self(
            $this->id,
            $this->courseId,
            $this->studentId,
            $this->assignedBy,
            $completion,
            $grade,
            $this->createdAt,
        );
    }
}
