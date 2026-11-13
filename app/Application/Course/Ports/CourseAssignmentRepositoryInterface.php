<?php

declare(strict_types=1);

namespace App\Application\Course\Ports;

use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\ValueObjects\CourseAssignmentId;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Репозиторий агрегата CourseAssignment.
 */
interface CourseAssignmentRepositoryInterface
{
    public function findById(CourseAssignmentId $id): ?CourseAssignment;

    public function findByCourseAndStudent(CourseId $courseId, UserId $studentId): ?CourseAssignment;

    public function save(CourseAssignment $assignment): CourseAssignment;

    public function delete(CourseAssignmentId $id): void;

    /**
     * Все назначения курса (для снятия и для листинга).
     *
     * @return list<CourseAssignment>
     */
    public function listByCourse(CourseId $courseId): array;

    /**
     * Все назначения ученика.
     *
     * @return list<CourseAssignment>
     */
    public function listByStudent(UserId $studentId): array;

    public function exists(CourseId $courseId, UserId $studentId): bool;
}
