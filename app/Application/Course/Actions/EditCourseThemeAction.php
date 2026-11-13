<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Events\CourseChanged;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseThemeId;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Операции над темами курса: добавить, переименовать, удалить, переставить.
 *
 * Темы входят в состав курса, поэтому любая операция публикует CourseChanged
 * (слушатель пересчитает completion/grade всех назначений).
 */
final readonly class EditCourseThemeAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
        private Dispatcher $events,
    ) {}

    public function add(
        CourseViewerContext $viewer,
        CourseId $courseId,
        string $title,
        ?DateTimeImmutable $now = null,
    ): Course {
        return $this->mutate(
            $viewer,
            $courseId,
            fn (Course $course) => $course->addTheme($title),
            $now,
        );
    }

    public function rename(
        CourseViewerContext $viewer,
        CourseId $courseId,
        CourseThemeId $themeId,
        string $title,
        ?DateTimeImmutable $now = null,
    ): Course {
        return $this->mutate(
            $viewer,
            $courseId,
            fn (Course $course) => $course->renameTheme($themeId, $title),
            $now,
        );
    }

    public function remove(
        CourseViewerContext $viewer,
        CourseId $courseId,
        CourseThemeId $themeId,
        ?DateTimeImmutable $now = null,
    ): Course {
        return $this->mutate(
            $viewer,
            $courseId,
            fn (Course $course) => $course->removeTheme($themeId),
            $now,
        );
    }

    public function move(
        CourseViewerContext $viewer,
        CourseId $courseId,
        CourseThemeId $themeId,
        int $toPosition,
        ?DateTimeImmutable $now = null,
    ): Course {
        return $this->mutate(
            $viewer,
            $courseId,
            fn (Course $course) => $course->moveTheme($themeId, $toPosition),
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
