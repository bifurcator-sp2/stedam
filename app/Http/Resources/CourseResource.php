<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseLesson;
use App\Domain\Course\Entities\CourseTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Полный курс с составом (§1.1 course-api-spec).
 *
 * @mixin Course
 */
final class CourseResource extends JsonResource
{
    /**
     * @param bool $withThemes отдавать ли состав. В листинге схема без themes
     *                         (§1.2): пустой массив там сбивает с толку —
     *                         выглядит как «курс без тем», а не «не запросили».
     */
    public function __construct(Course $course, private bool $withThemes = true)
    {
        parent::__construct($course);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Course $course */
        $course = $this->resource;

        $data = [
            'id' => $course->id()?->value,
            'user_id' => $course->userId()?->value,
            'is_system' => $course->userId() === null,
            'title' => $course->title()->value,
            'description' => $course->description()->value,
            'locale' => $course->locale()->value,
            'next_lesson_threshold' => $course->nextLessonThreshold()->value,
            'tax_id' => $course->taxId()?->value,
            'updated_at' => $course->updatedAt()?->format(DATE_ATOM),
            'lessons_count' => $course->countLessons(),
        ];

        if (!$this->withThemes) {
            return $data;
        }

        return [
            ...$data,
            'themes' => array_map(
                static fn (CourseTheme $theme): array => [
                    'id' => $theme->id()?->value,
                    'title' => $theme->title(),
                    'order' => $theme->order(),
                    'lessons' => array_map(
                        static fn (CourseLesson $lesson): array => [
                            'lesson_id' => $lesson->lessonId()->value,
                            'order' => $lesson->order(),
                            'type_id' => $lesson->typeId()?->value,
                            'time_gap' => $lesson->timeGap()->hours,
                        ],
                        $theme->lessons(),
                    ),
                ],
                $course->themes(),
            ),
        ];
    }
}
