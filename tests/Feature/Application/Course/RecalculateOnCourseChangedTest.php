<?php

declare(strict_types=1);

use App\Application\Course\Services\CourseGradeRecalculator;
use App\Application\Course\Strategies\AverageLessonGradesStrategy;
use App\Application\Course\Strategies\LessonCountCompletionStrategy;
use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\Events\CourseChanged;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\UserId;
use App\Listeners\Course\RecalculateOnCourseChanged;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Tests\Support\CourseScene;
use Tests\Support\Fakes\InMemoryLearnPort;

/**
 * После смены состава курса у всех назначений пересчитываются
 * completion/grade — иначе статистика «залипает».
 */
function makeRecalcListener(CourseScene $scene, InMemoryLearnPort $learn): RecalculateOnCourseChanged
{
    return new RecalculateOnCourseChanged(
        $scene->courses,
        $scene->assignments,
        new CourseGradeRecalculator(
            $learn,
            new AverageLessonGradesStrategy(),
            new LessonCountCompletionStrategy(),
        ),
    );
}

function courseChanged(CourseId $courseId): CourseChanged
{
    return new CourseChanged($courseId);
}

it('пересчитывает все назначения курса', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]]);

    foreach ([5, 6] as $studentId) {
        $scene->assignments->add(CourseAssignment::create(
            courseId: $course->id(),
            studentId: new UserId($studentId),
            assignedBy: new UserId(1),
            now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
        ));
    }

    // Ученик 5 сдал оба урока, ученик 6 — один.
    $learn->setGrade(new UserId(5), new LessonId(10), 1.0);
    $learn->setGrade(new UserId(5), new LessonId(20), 1.0);
    $learn->setGrade(new UserId(6), new LessonId(10), 0.5);

    makeRecalcListener($scene, $learn)->handle(courseChanged($course->id()));

    $a5 = $scene->assignments->findByCourseAndStudent($course->id(), new UserId(5));
    $a6 = $scene->assignments->findByCourseAndStudent($course->id(), new UserId(6));

    expect($a5->completion()->value)->toBe(1.0)
        ->and($a5->grade()->value)->toBe(1.0)
        ->and($a6->completion()->value)->toBe(0.5)
        ->and($a6->grade()->value)->toBe(0.5);
});

it('обнуляет completion, когда уроков стало больше', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20, 30]]);
    $student = new UserId(5);

    $scene->assignments->add(CourseAssignment::create(
        courseId: $course->id(),
        studentId: $student,
        assignedBy: new UserId(1),
        now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    ));

    $learn->setGrade($student, new LessonId(10), 1.0);

    makeRecalcListener($scene, $learn)->handle(courseChanged($course->id()));

    $assignment = $scene->assignments->findByCourseAndStudent($course->id(), $student);

    // Добавили 2 урока — пройденность упала до 1/3.
    expect($assignment->completion()->value)->toBe(1 / 3);
});

it('сохраняет назначения, а не только считает в памяти', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
    $student = new UserId(5);

    $scene->assignments->add(CourseAssignment::create(
        courseId: $course->id(),
        studentId: $student,
        assignedBy: new UserId(1),
        now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    ));

    $learn->setGrade($student, new LessonId(10), 0.8);

    makeRecalcListener($scene, $learn)->handle(courseChanged($course->id()));

    // Читаем заново из репозитория — значит save() действительно вызван.
    $saved = $scene->assignments->findByCourseAndStudent($course->id(), $student);

    expect($saved->grade()->value)->toBe(0.8);
});

it('не падает, если назначений нет', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

    makeRecalcListener($scene, $learn)->handle(courseChanged($course->id()));

    expect($scene->assignments->count())->toBe(0);
});

it('не делает ничего, если курс удалён', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();

    Log::shouldReceive('warning')->once();

    makeRecalcListener($scene, $learn)->handle(courseChanged(new CourseId(999)));

    expect($scene->assignments->count())->toBe(0);
});

it('стоит в очереди после коммита транзакции', function () {
    expect(makeRecalcListener(new CourseScene(), new InMemoryLearnPort()))
        ->toBeInstanceOf(ShouldQueueAfterCommit::class);
});
