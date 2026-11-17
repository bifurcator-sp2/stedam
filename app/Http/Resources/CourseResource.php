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
 * Форма ответа одинакова у GET (show), POST (store) и PATCH/PUT (update) —
 * фронт может заменять состояние курса ответом любой мутации.
 *
 * @mixin Course
 */
final class CourseResource extends JsonResource
{
    /**
     * @param bool $withThemes отдавать ли состав. В листинге схема без themes
     *                         (§1.2): пустой массив там сбивает с толку —
     *                         выглядит как «курс без тем», а не «не запросили».
     * @param array<int, array{type: string|null, title: string|null, description: string|null, complexity: float|null}> $lessonSummaries
     *                         карточки уроков (LessonReaderInterface::summariesOf)
     *                         по id урока. Отсутствующий урок — null-поля.
     */
    public function __construct(
        Course $course,
        private bool $withThemes = true,
        private array $lessonSummaries = [],
    ) {
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
            'threshold_multipler' => $course->thresholdMultiplier()->value,
            'time_gap_multipler' => $course->timeGapMultiplier()->value,
            'tax_id' => $course->taxId()?->value,
            'published' => $course->isPublished(),
            'updated_at' => $course->updatedAt()?->format(DATE_ATOM),
            'lessons_count' => $course->countLessons(),
        ];

        if (!$this->withThemes) {
            return $data;
        }

        return [
            ...$data,
            'themes' => array_map(
                fn (CourseTheme $theme): array => [
                    'id' => $theme->id()?->value,
                    'title' => $theme->title(),
                    'order' => $theme->order(),
                    'lessons' => array_map(
                        fn (CourseLesson $lesson): array => $this->lessonToArray($lesson),
                        $theme->lessons(),
                    ),
                ],
                $course->themes(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonToArray(CourseLesson $lesson): array
    {
        $id = $lesson->lessonId()->value;
        $summary = $this->lessonSummaries[$id] ?? null;

        return [
            'lesson_id' => $id,
            'id' => $id,
            'order' => $lesson->order(),
            'time_gap' => $lesson->timeGap()->hours,
            // Код типа урока (lessons.type): class_work, home_work, ...
            'type' => $summary['type'] ?? null,
            'title' => $summary['title'] ?? null,
            'description' => $summary['description'] ?? null,
            // Сложность 0..1 (не проценты, §0.4).
            'complexity' => $summary['complexity'] ?? null,
        ];
    }
}
