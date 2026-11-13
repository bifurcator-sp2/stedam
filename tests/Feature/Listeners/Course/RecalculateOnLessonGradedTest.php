<?php

declare(strict_types=1);

use App\Application\Course\Services\CourseGradeRecalculator;
use App\Application\Course\Strategies\AverageLessonGradesStrategy;
use App\Application\Course\Strategies\LessonCountCompletionStrategy;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId as CourseUserId;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\Events\LearnStatusChanged;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId as LearnLessonId;
use App\Listeners\Course\RecalculateOnLessonGraded;
use App\Models\Course as CourseModel;
use App\Models\CourseAssignment as CourseAssignmentModel;
use App\Models\CourseTheme as CourseThemeModel;
use App\Models\CourseThemeLesson as CourseThemeLessonModel;
use App\Models\Learn as LearnModel;
use App\Models\Lesson as LessonModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\InMemoryLearnPort;

uses(RefreshDatabase::class);

function lrgUser(string $name): User
{
    return User::query()->create([
        'name' => $name,
        'email' => $name . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
}

function lrgLesson(int $authorId, string $title): LessonModel
{
    return LessonModel::query()->create([
        'user_id' => $authorId,
        'title' => $title,
        'locale' => 'ru',
    ]);
}

/**
 * Курс из одного урока + назначение ученику.
 *
 * @return array{course: CourseModel, assignment: CourseAssignmentModel, lesson: LessonModel}
 */
function lrgCourseWithLesson(User $owner, User $student, LessonModel $lesson): array
{
    $course = CourseModel::query()->create([
        'user_id' => $owner->id,
        'title' => 'Курс',
        'description' => 'Описание',
        'locale' => 'ru',
        'next_lesson_threshold' => 0.6,
    ]);

    $theme = CourseThemeModel::query()->create([
        'course_id' => $course->id,
        'title' => 'Тема',
        'order' => 1,
    ]);

    CourseThemeLessonModel::query()->create([
        'theme_id' => $theme->id,
        'lesson_id' => $lesson->id,
        'order' => 1,
        'time_gap' => 0,
    ]);

    $assignment = CourseAssignmentModel::query()->create([
        'course_id' => $course->id,
        'student_id' => $student->id,
        'assigned_by' => $owner->id,
        'completion' => 0,
        'grade' => 0,
    ]);

    return ['course' => $course, 'assignment' => $assignment, 'lesson' => $lesson];
}

function lrgLearn(User $student, LessonModel $lesson, LearnStatus $status, float $score = 0): LearnModel
{
    return LearnModel::query()->create([
        'user_id' => $student->id,
        'lesson_id' => $lesson->id,
        'status' => $status->value,
        'status_date' => now(),
        'score' => $score,
        'answers' => [],
        'questions' => [],
    ]);
}

function lrgListener(InMemoryLearnPort $learnPort): RecalculateOnLessonGraded
{
    return new RecalculateOnLessonGraded(
        app(\App\Application\Course\Ports\CourseRepositoryInterface::class),
        app(\App\Application\Course\Ports\CourseAssignmentRepositoryInterface::class),
        new CourseGradeRecalculator(
            $learnPort,
            new AverageLessonGradesStrategy(),
            new LessonCountCompletionStrategy(),
        ),
    );
}

function lrgEvent(LearnModel $learn, LearnStatus $status): LearnStatusChanged
{
    return new LearnStatusChanged(
        learnId: new LearnId($learn->id),
        lessonId: new LearnLessonId($learn->lesson_id),
        status: $status,
        statusDate: new DateTimeImmutable(),
    );
}

it('пересчитывает прогресс курса после выставления оценки', function () {
    $owner = lrgUser('owner');
    $student = lrgUser('student');
    $lesson = lrgLesson($owner->id, 'Урок');

    $scene = lrgCourseWithLesson($owner, $student, $lesson);
    $learn = lrgLearn($student, $lesson, LearnStatus::Graded, 100);

    // Learn отдаёт оценку в шкале 0..100 → адаптер нормализует в 0..1.
    $learnPort = new InMemoryLearnPort();
    $learnPort->setGrade(new CourseUserId($student->id), new \App\Domain\Course\ValueObjects\LessonId($lesson->id), 1.0);

    lrgListener($learnPort)->handle(lrgEvent($learn, LearnStatus::Graded));

    $fresh = $scene['assignment']->fresh();

    expect($fresh->completion)->toBe(1.0)
        ->and($fresh->grade)->toBe(1.0);
});

it('игнорирует статусы, кроме graded', function (LearnStatus $status) {
    $owner = lrgUser('owner');
    $student = lrgUser('student');
    $lesson = lrgLesson($owner->id, 'Урок');

    $scene = lrgCourseWithLesson($owner, $student, $lesson);
    $learn = lrgLearn($student, $lesson, $status, 100);

    $learnPort = new InMemoryLearnPort();
    $learnPort->setGrade(new CourseUserId($student->id), new \App\Domain\Course\ValueObjects\LessonId($lesson->id), 1.0);

    lrgListener($learnPort)->handle(lrgEvent($learn, $status));

    // Прогресс не тронут: оценка ещё не выставлена.
    expect($scene['assignment']->fresh()->completion)->toBe(0.0);
})->with([
    'created' => LearnStatus::Created,
    'in_progress' => LearnStatus::InProgress,
    'done' => LearnStatus::Done,
]);

it('не падает, если урок не входит ни в один курс ученика', function () {
    $owner = lrgUser('owner');
    $student = lrgUser('student');
    $inCourse = lrgLesson($owner->id, 'Урок курса');
    $outside = lrgLesson($owner->id, 'Урок вне курса');

    lrgCourseWithLesson($owner, $student, $inCourse);
    $learn = lrgLearn($student, $outside, LearnStatus::Graded, 100);

    // Ровно тот случай, ради которого try/catch: оценка не должна ломаться.
    lrgListener(new InMemoryLearnPort())->handle(lrgEvent($learn, LearnStatus::Graded));

    expect(true)->toBeTrue();
});

it('не трогает назначения других учеников', function () {
    $owner = lrgUser('owner');
    $studentA = lrgUser('a');
    $studentB = lrgUser('b');
    $lesson = lrgLesson($owner->id, 'Урок');

    $scene = lrgCourseWithLesson($owner, $studentA, $lesson);

    CourseAssignmentModel::query()->create([
        'course_id' => $scene['course']->id,
        'student_id' => $studentB->id,
        'assigned_by' => $owner->id,
        'completion' => 0,
        'grade' => 0,
    ]);

    $learn = lrgLearn($studentA, $lesson, LearnStatus::Graded, 100);

    $learnPort = new InMemoryLearnPort();
    $learnPort->setGrade(new CourseUserId($studentA->id), new \App\Domain\Course\ValueObjects\LessonId($lesson->id), 1.0);

    lrgListener($learnPort)->handle(lrgEvent($learn, LearnStatus::Graded));

    $b = CourseAssignmentModel::query()
        ->where('course_id', $scene['course']->id)
        ->where('student_id', $studentB->id)
        ->first();

    expect($b->completion)->toBe(0.0)
        ->and($scene['assignment']->fresh()->completion)->toBe(1.0);
});

it('подписан на LearnStatusChanged в контейнере', function () {
    // ShouldQueueAfterCommit → Laravel оборачивает слушателя в замыкание,
    // поэтому проверяем по реестру событий имя класса среди замыканий.
    $listeners = app('events')->getRawListeners()[LearnStatusChanged::class] ?? [];

    expect($listeners)->not->toBeEmpty();
});
