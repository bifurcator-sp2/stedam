<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Course\Entities\CourseAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Назначение курса ученику (§1.3 course-api-spec).
 *
 * @mixin CourseAssignment
 */
final class CourseAssignmentResource extends JsonResource
{
    public function __construct(CourseAssignment $assignment)
    {
        parent::__construct($assignment);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CourseAssignment $assignment */
        $assignment = $this->resource;

        return [
            'id' => $assignment->id()?->value,
            'course_id' => $assignment->courseId()->value,
            'student_id' => $assignment->studentId()->value,
            'assigned_by' => $assignment->assignedBy()->value,
            'completion' => $assignment->completion()->value,
            'grade' => $assignment->grade()->value,
            'created_at' => $assignment->createdAt()->format(DATE_ATOM),
        ];
    }
}
