<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Course\Ports\LessonReaderInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Events\CourseChanged;
use App\Domain\Course\Exceptions\CourseLessonNotInTheme;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseThemeId;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\LessonTypeId;
use App\Domain\Course\ValueObjects\TimeGap;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Операции над уроками внутри темы: добавить, убрать, переставить,
 * сменить тип, сменить разрыв.
 *
 * addLesson проверяет существование урока в контексте Lessons: в курс
 * нельзя сослаться на несуществующий урок.
 *
 * Любая операция меняет состав курса → публикует CourseChanged.
 */
final readonly class EditCourseLessonAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
        private LessonReaderInterface $lessons,
        private Dispatcher $events,
    ) {}

    public function add(
        CourseViewerContext $viewer,
        CourseId $courseId,
        CourseThemeId $themeId,
        LessonId $lessonId,
        ?LessonTypeId $typeId = null,
        ?TimeGap $timeGap = null,
        ?DateTimeImmutable $now = null,
    ): Course {
        if (!$this->lessons->exists($lessonId)) {
            throw new CourseLessonNotInTheme(
                "Lesson {$lessonId->value} does not exist"
            );
        }

        return $this->mutate(
            $viewer,
            $courseId,
            fn (Course $course) => $course->addLesson($themeId, $lessonId, $typeId, $timeGap),
            $now,
        );
    }

    public function remove(
        CourseViewerContext $viewer,
        CourseId $courseId,
        CourseThemeId $themeId,
        LessonId $lessonId,
        ?DateTimeImmutable $now = null,
    ): Course {
        return $this->mutate(
            $viewer,
            $courseId,
            fn (Course $course) => $course->removeLesson($themeId, $lessonId),
            $now,
        );
    }

    public function move(
        CourseViewerContext $viewer,
        CourseId $courseId,
        CourseThemeId $themeId,
        LessonId $lessonId,
        int $toPosition,
        ?DateTimeImmutable $now = null,
    ): Course {
        return $this->mutate(
            $viewer,
            $courseId,
            fn (Course $course) => $course->moveLesson($themeId, $lessonId, $toPosition),
            $now,
        );
    }

    public function changeType(
        CourseViewerContext $viewer,
        CourseId $courseId,
        CourseThemeId $themeId,
        LessonId $lessonId,
        ?LessonTypeId $typeId,
        ?DateTimeImmutable $now = null,
    ): Course {
        return $this->mutate(
            $viewer,
            $courseId,
            fn (Course $course) => $course->changeLessonType($themeId, $lessonId, $typeId),
            $now,
        );
    }

    public function changeTimeGap(
        CourseViewerContext $viewer,
        CourseId $courseId,
        CourseThemeId $themeId,
        LessonId $lessonId,
        TimeGap $timeGap,
        ?DateTimeImmutable $now = null,
    ): Course {
        return $this->mutate(
            $viewer,
            $courseId,
            fn (Course $course) => $course->changeLessonTimeGap($themeId, $lessonId, $timeGap),
            $now,
        );
    }

    /**
     * @param callable(Course): Course $mutation
     */
    private function mutate(
        CourseViewerContext $viewer,
        CourseId $courseId,
        callable $mutation,
        ?DateTimeImmutable $now,
    ): Course {
        $course = $this->courses->findById($courseId)
            ?? throw CourseNotFound::withId($courseId->value);

        if (!$viewer->canEdit($course->userId())) {
            throw CourseAccessDenied::edit($courseId->value);
        }

        $saved = $this->courses->save(
            $mutation($course)->touch($now ?? new DateTimeImmutable()),
        );

        $this->events->dispatch(new CourseChanged($courseId));

        return $saved;
    }
}
