<?php

declare(strict_types=1);

use App\Domain\Course\Events\CourseAssignmentRemoved;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\UserId;
use App\Listeners\Course\UnenrollOnAssignmentRemoved;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Tests\Support\CourseScene;
use Tests\Support\Fakes\InMemoryLearnPort;

/**
 * §17.2: при снятии назначения удаляем ТОЛЬКО не начатые прохождения.
 *
 * Сданные и начатые не трогаем — ученик мог сделать работу.
 */
function makeUnenrollListener(CourseScene $scene, InMemoryLearnPort $learn): UnenrollOnAssignmentRemoved
{
    return new UnenrollOnAssignmentRemoved($scene->courses, $learn);
}

function assignmentRemoved(CourseId $courseId, int $studentId): CourseAssignmentRemoved
{
    return new CourseAssignmentRemoved(
        courseId: $courseId,
        studentId: new UserId($studentId),
    );
}

it('удаляет не начатые прохождения всех уроков', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20, 30]]);
    $student = new UserId(5);

    foreach ([10, 20, 30] as $id) {
        $learn->enrollLesson($student, new LessonId($id));
    }

    makeUnenrollListener($scene, $learn)->handle(assignmentRemoved($course->id(), 5));

    expect($learn->enrolledCount())->toBe(0);
});

it('сохраняет начатое прохождение', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]]);
    $student = new UserId(5);

    $learn->enrollLesson($student, new LessonId(10));
    $learn->enrollLesson($student, new LessonId(20));

    // Урок 10 ученик начал проходить — его стирать нельзя (§17.2).
    $learn->markStarted($student, new LessonId(10));

    makeUnenrollListener($scene, $learn)->handle(assignmentRemoved($course->id(), 5));

    expect($learn->isEnrolled($student, new LessonId(10)))->toBeTrue()
        ->and($learn->isEnrolled($student, new LessonId(20)))->toBeFalse()
        ->and($learn->enrolledCount())->toBe(1);
});

it('сохраняет сданный урок', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]]);
    $student = new UserId(5);

    $learn->enrollLesson($student, new LessonId(10));
    $learn->setGrade($student, new LessonId(10), 0.9);
    $learn->markStarted($student, new LessonId(10));

    makeUnenrollListener($scene, $learn)->handle(assignmentRemoved($course->id(), 5));

    // Оценка на месте — работа ученика не пропала.
    expect($learn->gradeOf($student, new LessonId(10)))->toBe(0.9);
});

it('не трогает прохождения другого ученика', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

    $learn->enrollLesson(new UserId(5), new LessonId(10));
    $learn->enrollLesson(new UserId(6), new LessonId(10));

    makeUnenrollListener($scene, $learn)->handle(assignmentRemoved($course->id(), 5));

    expect($learn->isEnrolled(new UserId(5), new LessonId(10)))->toBeFalse()
        ->and($learn->isEnrolled(new UserId(6), new LessonId(10)))->toBeTrue();
});

it('не падает, если прохождений вообще не было', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

    makeUnenrollListener($scene, $learn)->handle(assignmentRemoved($course->id(), 5));

    expect($learn->enrolledCount())->toBe(0);
});

it('не делает ничего, если курс удалён', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();

    Log::shouldReceive('warning')->once();

    makeUnenrollListener($scene, $learn)->handle(assignmentRemoved(new CourseId(999), 5));

    expect($learn->enrolledCount())->toBe(0);
});

it('стоит в очереди после коммита транзакции', function () {
    expect(makeUnenrollListener(new CourseScene(), new InMemoryLearnPort()))
        ->toBeInstanceOf(ShouldQueueAfterCommit::class);
});
