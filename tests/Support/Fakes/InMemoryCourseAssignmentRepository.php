<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Course\Ports\CourseAssignmentRepositoryInterface;
use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\ValueObjects\CourseAssignmentId;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Назначения курсов в памяти — для тестов Application-слоя.
 */
final class InMemoryCourseAssignmentRepository implements CourseAssignmentRepositoryInterface
{
    /** @var array<int, CourseAssignment> */
    private array $items = [];

    private int $nextId = 1;

    public int $deleted = 0;

    public function add(CourseAssignment $assignment): CourseAssignment
    {
        if ($assignment->id() === null) {
            $assignment = $assignment->withId(new CourseAssignmentId($this->nextId++));
        }

        $this->items[$assignment->id()->value] = $assignment;

        return $assignment;
    }

    public function findById(CourseAssignmentId $id): ?CourseAssignment
    {
        return $this->items[$id->value] ?? null;
    }

    public function findByCourseAndStudent(CourseId $courseId, UserId $studentId): ?CourseAssignment
    {
        foreach ($this->items as $assignment) {
            if ($assignment->courseId()->value === $courseId->value
                && $assignment->studentId()->equals($studentId)
            ) {
                return $assignment;
            }
        }

        return null;
    }

    public function save(CourseAssignment $assignment): CourseAssignment
    {
        if ($assignment->id() === null) {
            $assignment = $assignment->withId(new CourseAssignmentId($this->nextId++));
        }

        $this->items[$assignment->id()->value] = $assignment;

        return $assignment;
    }

    public function delete(CourseAssignmentId $id): void
    {
        $this->deleted++;

        unset($this->items[$id->value]);
    }

    public function listByCourse(CourseId $courseId): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (CourseAssignment $a): bool => $a->courseId()->value === $courseId->value,
        ));
    }

    public function listByStudent(UserId $studentId): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (CourseAssignment $a): bool => $a->studentId()->equals($studentId),
        ));
    }

    public function exists(CourseId $courseId, UserId $studentId): bool
    {
        return $this->findByCourseAndStudent($courseId, $studentId) !== null;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Все назначения — для снимка состояния в тестах отката.
     *
     * @return list<CourseAssignment>
     */
    public function all(): array
    {
        return array_values($this->items);
    }

    /**
     * Заменить содержимое целиком — «откат» к ранее снятому снимку.
     *
     * @param list<CourseAssignment> $assignments
     */
    public function replaceAll(array $assignments): void
    {
        $this->items = [];

        foreach ($assignments as $assignment) {
            if ($assignment->id() === null) {
                $assignment = $assignment->withId(new CourseAssignmentId($this->nextId++));
            }

            $this->items[$assignment->id()->value] = $assignment;
        }
    }
}
