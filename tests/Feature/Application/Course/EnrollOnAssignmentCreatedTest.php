<?php

declare(strict_types=1);

use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Course\Ports\LearnPortInterface;
use App\Domain\Course\Events\CourseAssignmentCreated;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\UserId;
use App\Listeners\Course\EnrollOnAssignmentCreated;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Tests\Support\CourseScene;
use Tests\Support\Fakes\InMemoryLearnPort;

/**
 * §12.2: назначили курс — у ученика появились прохождения всех уроков.
 *
 * Слушатель вызывается напрямую через handle(): он ShouldQueueAfterCommit,
 * а проверяем мы его логику, а не доставку из очереди.
 */
function makeEnrollListener(CourseScene $scene, InMemoryLearnPort $learn): EnrollOnAssignmentCreated
{
    return new EnrollOnAssignmentCreated($scene->courses, $learn);
}

function assignmentCreated(CourseId $courseId, int $studentId): CourseAssignmentCreated
{
    return new CourseAssignmentCreated(
        courseId: $courseId,
        studentId: new UserId($studentId),
        assignedBy: new UserId(1),
    );
}

it('зачисляет ученика на все уроки курса', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons([
        'Тема 1' => [10, 20],
        'Тема 2' => [30, 40],
    ]);

    makeEnrollListener($scene, $learn)->handle(assignmentCreated($course->id(), 5));

    $student = new UserId(5);

    expect($learn->isEnrolled($student, new \App\Domain\Course\ValueObjects\LessonId(10)))->toBeTrue()
        ->and($learn->isEnrolled($student, new \App\Domain\Course\ValueObjects\LessonId(20)))->toBeTrue()
        ->and($learn->isEnrolled($student, new \App\Domain\Course\ValueObjects\LessonId(30)))->toBeTrue()
        ->and($learn->isEnrolled($student, new \App\Domain\Course\ValueObjects\LessonId(40)))->toBeTrue()
        ->and($learn->enrolledCount())->toBe(4);
});

it('зачисляет уроки из всех тем, в плоском порядке курса', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons([
        'Тема A' => [10],
        'Тема B' => [20],
    ]);

    makeEnrollListener($scene, $learn)->handle(assignmentCreated($course->id(), 5));

    // enrolledCount — сколько уникальных пар (ученик, урок) заведено.
    expect($learn->enrolledCount())->toBe(2);
});

it('идемпотентен: повторная обработка не создаёт дублей', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]]);
    $listener = makeEnrollListener($scene, $learn);
    $event = assignmentCreated($course->id(), 5);

    $listener->handle($event);
    $listener->handle($event);

    expect($learn->enrolledCount())->toBe(2);
});

it('не зачисляет ничего, если курс удалён', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();

    // Слушатель пишет предупреждение — заизолируем лог от прав на файл.
    Log::shouldReceive('warning')->once();

    makeEnrollListener($scene, $learn)->handle(assignmentCreated(new CourseId(999), 5));

    expect($learn->enrolledCount())->toBe(0);
});

it('не зачисляет на курс без уроков', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Пустая тема' => []]);

    makeEnrollListener($scene, $learn)->handle(assignmentCreated($course->id(), 5));

    expect($learn->enrolledCount())->toBe(0);
});

it('зачисляет только своему ученику, не остальным', function () {
    $scene = new CourseScene();
    $learn = new InMemoryLearnPort();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

    makeEnrollListener($scene, $learn)->handle(assignmentCreated($course->id(), 5));

    expect($learn->isEnrolled(new UserId(5), new \App\Domain\Course\ValueObjects\LessonId(10)))->toBeTrue()
        ->and($learn->isEnrolled(new UserId(6), new \App\Domain\Course\ValueObjects\LessonId(10)))->toBeFalse();
});

it('стоит в очереди после коммита транзакции', function () {
    expect(new EnrollOnAssignmentCreated(
        app(CourseRepositoryInterface::class),
        app(LearnPortInterface::class),
    ))->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueueAfterCommit::class);
});
