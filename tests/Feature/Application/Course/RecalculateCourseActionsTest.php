<?php

declare(strict_types=1);

use App\Application\Course\Actions\RecalculateCourseAction;
use App\Application\Course\Actions\RecalculateCourseForAllAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Services\CourseGradeRecalculator;
use App\Application\Course\Strategies\AverageLessonGradesStrategy;
use App\Application\Course\Strategies\LessonCountCompletionStrategy;
use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;
use Tests\Support\Fakes\InMemoryLearnPort;

function recalculator(InMemoryLearnPort $learn): CourseGradeRecalculator
{
    return new CourseGradeRecalculator(
        $learn,
        new AverageLessonGradesStrategy(),
        new LessonCountCompletionStrategy(),
    );
}

function addAssignmentFor(CourseScene $scene, CourseId $courseId, int $studentId): void
{
    $scene->assignments->add(CourseAssignment::create(
        courseId: $courseId,
        studentId: new UserId($studentId),
        assignedBy: new UserId(1),
        now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    ));
}

describe('RecalculateCourseAction', function () {
    it('пересчитывает одно назначение', function () {
        $scene = new CourseScene();
        $learn = new InMemoryLearnPort();
        $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]]);
        addAssignmentFor($scene, $course->id(), 5);
        $learn->setGrade(new UserId(5), new LessonId(10), 1.0);

        $action = new RecalculateCourseAction(
            $scene->courses,
            $scene->assignments,
            recalculator($learn),
        );

        $updated = $action->execute(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
            new UserId(5),
        );

        expect($updated->grade()->value)->toBe(1.0)
            ->and($updated->completion()->value)->toBe(0.5);
    });

    it('учитель не пересчитывает чужого ученика', function () {
        $scene = new CourseScene();
        $learn = new InMemoryLearnPort();
        $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));
        addAssignmentFor($scene, $course->id(), 5);

        $action = new RecalculateCourseAction(
            $scene->courses,
            $scene->assignments,
            recalculator($learn),
        );

        expect(
            fn () => $action->execute(
                CourseViewerContext::teacher(new UserId(42)), // не владелец
                $course->id(),
                new UserId(5),
            ),
        )->toThrow(CourseAccessDenied::class);
    });

    it('ученик не пересчитывает чужой прогресс', function () {
        $scene = new CourseScene();
        $learn = new InMemoryLearnPort();
        $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
        addAssignmentFor($scene, $course->id(), 5);

        $action = new RecalculateCourseAction(
            $scene->courses,
            $scene->assignments,
            recalculator($learn),
        );

        expect(
            fn () => $action->execute(
                CourseViewerContext::student(new UserId(7)),
                $course->id(),
                new UserId(5),
            ),
        )->toThrow(CourseAccessDenied::class);
    });

    it('отказывает, если назначения нет', function () {
        $scene = new CourseScene();
        $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

        $action = new RecalculateCourseAction(
            $scene->courses,
            $scene->assignments,
            recalculator(new InMemoryLearnPort()),
        );

        expect(
            fn () => $action->execute(
                CourseViewerContext::admin(new UserId(1)),
                $course->id(),
                new UserId(5),
            ),
        )->toThrow(CourseNotFound::class);
    });

    it('отказывает, если курса нет', function () {
        $scene = new CourseScene();

        $action = new RecalculateCourseAction(
            $scene->courses,
            $scene->assignments,
            recalculator(new InMemoryLearnPort()),
        );

        expect(
            fn () => $action->execute(
                CourseViewerContext::admin(new UserId(1)),
                new CourseId(999),
                new UserId(5),
            ),
        )->toThrow(CourseNotFound::class);
    });
});

describe('RecalculateCourseForAllAction', function () {
    it('пересчитывает всех учеников и возвращает их число', function () {
        $scene = new CourseScene();
        $learn = new InMemoryLearnPort();
        $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

        foreach ([5, 6, 7] as $studentId) {
            addAssignmentFor($scene, $course->id(), $studentId);
        }

        $learn->setGrade(new UserId(5), new LessonId(10), 1.0);

        $action = new RecalculateCourseForAllAction(
            $scene->courses,
            $scene->assignments,
            recalculator($learn),
        );

        $count = $action->execute(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
        );

        expect($count)->toBe(3)
            ->and($scene->assignments->findByCourseAndStudent($course->id(), new UserId(5))->completion()->value)->toBe(1.0)
            ->and($scene->assignments->findByCourseAndStudent($course->id(), new UserId(6))->completion()->value)->toBe(0.0);
    });

    it('возвращает 0, если учеников нет', function () {
        $scene = new CourseScene();
        $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

        $action = new RecalculateCourseForAllAction(
            $scene->courses,
            $scene->assignments,
            recalculator(new InMemoryLearnPort()),
        );

        expect($action->execute(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
        ))->toBe(0);
    });

    it('отказывает без прав редактирования', function () {
        $scene = new CourseScene();
        $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));

        $action = new RecalculateCourseForAllAction(
            $scene->courses,
            $scene->assignments,
            recalculator(new InMemoryLearnPort()),
        );

        expect(
            fn () => $action->execute(
                CourseViewerContext::teacher(new UserId(42)),
                $course->id(),
            ),
        )->toThrow(CourseAccessDenied::class);
    });
});
