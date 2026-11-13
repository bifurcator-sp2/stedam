<?php

declare(strict_types=1);

use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\UserId;
use App\Models\Learn as LearnModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function learnRepo(): LearnRepositoryInterface
{
    return app(LearnRepositoryInterface::class);
}

function learnUser(string $name): User
{
    return User::query()->create([
        'name' => $name,
        'email' => $name . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
}

/** ≥2 урока нужны, чтобы проверять «не тот урок». */
function learnLessonIds(int $count = 2): array
{
    $author = learnUser('author');
    $ids = [];

    for ($i = 1; $i <= $count; $i++) {
        $ids[] = \App\Models\Lesson::query()->create([
            'user_id' => $author->id,
            'title' => "Урок {$i}",
            'locale' => 'ru',
        ])->id;
    }

    return $ids;
}

function learnRow(
    int $userId,
    int $lessonId,
    LearnStatus $status,
    ?DateTimeImmutable $startedAt = null,
): LearnModel {
    return LearnModel::query()->create([
        'user_id' => $userId,
        'lesson_id' => $lessonId,
        'status' => $status->value,
        'status_date' => now(),
        'started_at' => $startedAt,
        'answers' => [],
        'questions' => [],
    ]);
}

it('удаляет created без started_at', function () {
    [$lessonId] = learnLessonIds();
    $student = learnUser('student');
    learnRow($student->id, $lessonId, LearnStatus::Created);

    $deleted = learnRepo()->deleteUnstarted(new UserId($student->id), new LessonId($lessonId));

    expect($deleted)->toBeTrue()
        ->and(LearnModel::query()->where('user_id', $student->id)->exists())->toBeFalse();
});

it('удаляет scheduled без started_at', function () {
    [$lessonId] = learnLessonIds();
    $student = learnUser('student');
    learnRow($student->id, $lessonId, LearnStatus::Scheduled);

    expect(learnRepo()->deleteUnstarted(new UserId($student->id), new LessonId($lessonId)))->toBeTrue();
});

it('НЕ удаляет created со started_at (ученик уже открыл урок)', function () {
    [$lessonId] = learnLessonIds();
    $student = learnUser('student');
    learnRow($student->id, $lessonId, LearnStatus::Created, new DateTimeImmutable('2025-01-01 10:00:00 UTC'));

    // Гонка: статус ещё created, но работа фактически начата — нельзя стирать.
    expect(learnRepo()->deleteUnstarted(new UserId($student->id), new LessonId($lessonId)))->toBeFalse()
        ->and(LearnModel::query()->where('user_id', $student->id)->exists())->toBeTrue();
});

it('НЕ удаляет начатое прохождение in_progress', function () {
    [$lessonId] = learnLessonIds();
    $student = learnUser('student');
    learnRow($student->id, $lessonId, LearnStatus::InProgress, new DateTimeImmutable('2025-01-01 10:00:00 UTC'));

    expect(learnRepo()->deleteUnstarted(new UserId($student->id), new LessonId($lessonId)))->toBeFalse();
});

it('НЕ удаляет сданный урок graded', function () {
    [$lessonId] = learnLessonIds();
    $student = learnUser('student');
    learnRow($student->id, $lessonId, LearnStatus::Graded, new DateTimeImmutable('2025-01-01 10:00:00 UTC'));

    expect(learnRepo()->deleteUnstarted(new UserId($student->id), new LessonId($lessonId)))->toBeFalse();
});

it('НЕ удаляет завершённый done', function () {
    [$lessonId] = learnLessonIds();
    $student = learnUser('student');
    learnRow($student->id, $lessonId, LearnStatus::Done, new DateTimeImmutable('2025-01-01 10:00:00 UTC'));

    expect(learnRepo()->deleteUnstarted(new UserId($student->id), new LessonId($lessonId)))->toBeFalse();
});

it('не трогает чужое прохождение того же урока', function () {
    [$lessonId] = learnLessonIds();
    $mine = learnUser('mine');
    $other = learnUser('other');
    learnRow($mine->id, $lessonId, LearnStatus::Created);
    learnRow($other->id, $lessonId, LearnStatus::Created);

    learnRepo()->deleteUnstarted(new UserId($mine->id), new LessonId($lessonId));

    expect(LearnModel::query()->where('user_id', $other->id)->exists())->toBeTrue();
});

it('возвращает false, если удалять нечего', function () {
    [$lessonId] = learnLessonIds();
    $student = learnUser('student');

    expect(learnRepo()->deleteUnstarted(new UserId($student->id), new LessonId($lessonId)))->toBeFalse();
});
