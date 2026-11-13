<?php

declare(strict_types=1);

use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Domain\Learn\Entities\Learn as LearnEntity;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LearnAnswers;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Domain\Learn\ValueObjects\UserId;
use App\Models\Learn as LearnModel;
use App\Models\Lesson as LessonModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Round-trip startedAt: Eloquent-колонка → домен → обратно.
 *
 * deleteUnstarted() опирается на started_at IS NULL, а Cancel/Start —
 * на startedAt() из reconstitute(). Если маппинг потеряет поле, защита
 * «не стирать начатое» молча перестанет работать на живых данных.
 */
function rtRepo(): LearnRepositoryInterface
{
    return app(LearnRepositoryInterface::class);
}

function rtUser(string $name): User
{
    return User::query()->create([
        'name' => $name,
        'email' => $name . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
}

function rtLesson(int $authorId): LessonModel
{
    return LessonModel::query()->create([
        'user_id' => $authorId,
        'title' => 'Урок',
        'locale' => 'ru',
    ]);
}

it('startedAt сохраняется и читается обратно', function () {
    $author = rtUser('author');
    $lesson = rtLesson($author->id);
    $student = rtUser('student');

    $now = new DateTimeImmutable('2025-01-01 12:00:00 UTC');
    $learn = LearnEntity::startNow(
        userId: new UserId($student->id),
        lessonId: new LessonId($lesson->id),
        snapshot: SnapshotHash::fromContent('lesson'),
        questions: domainQuestions(1),
        now: $now,
    )->withId(rtRepo()->reserveId());

    $saved = rtRepo()->insert($learn);

    expect($saved->startedAt())->not->toBeNull()
        ->and($saved->startedAt()->getTimestamp())->toBe($now->getTimestamp());
});

it('startedAt === null у created-прохождения остаётся null после чтения', function () {
    $author = rtUser('author');
    $lesson = rtLesson($author->id);
    $student = rtUser('student');

    // save() — строгий UPDATE, для новой строки нужен reserveId() + insert().
    $learn = LearnEntity::assign(
        new UserId($student->id),
        new LessonId($lesson->id),
        new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    )->withId(rtRepo()->reserveId());

    $saved = rtRepo()->insert($learn);

    // Без startedAt() агрегат не отличит «назначено» от «начато».
    expect($saved->startedAt())->toBeNull();

    $fresh = rtRepo()->getById($saved->id());

    expect($fresh->startedAt())->toBeNull()
        ->and($fresh->status())->toBe(LearnStatus::Created);
});

it('scheduled без started_at читается с null — deleteUnstarted сработает', function () {
    $author = rtUser('author');
    $lesson = rtLesson($author->id);
    $student = rtUser('student');

    $scheduleDate = new ScheduleDate(new DateTimeImmutable('2025-02-01 10:00:00 UTC'));
    $learn = LearnEntity::schedule(
        new UserId($student->id),
        new LessonId($lesson->id),
        $scheduleDate,
        new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    )->withId(rtRepo()->reserveId());

    rtRepo()->insert($learn);

    $active = rtRepo()->findActiveByUserAndLesson(
        new UserId($student->id),
        new LessonId($lesson->id),
    );

    expect($active)->not->toBeNull()
        ->and($active->startedAt())->toBeNull()
        ->and($active->scheduleDate()?->value->getTimestamp())->toBe($scheduleDate->value->getTimestamp());
});

it('startedAt не теряется при сохранении начатого прохождения', function () {
    $author = rtUser('author');
    $lesson = rtLesson($author->id);
    $student = rtUser('student');

    // created без даты → ученик нажал «Пройти» → реальный старт.
    $learn = LearnEntity::assign(
        new UserId($student->id),
        new LessonId($lesson->id),
        new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    )->withId(rtRepo()->reserveId());
    $learn = rtRepo()->insert($learn);

    $startedAt = new DateTimeImmutable('2025-01-05 08:30:00 UTC');
    $learn->start(SnapshotHash::fromContent('lesson'), domainQuestions(1), $startedAt);
    $saved = rtRepo()->save($learn);

    expect($saved->startedAt()?->getTimestamp())->toBe($startedAt->getTimestamp());

    // Перечитываем из БД: колонка записалась, маппинг не потерял.
    $fresh = rtRepo()->getById($saved->id());

    expect($fresh->startedAt()?->getTimestamp())->toBe($startedAt->getTimestamp())
        ->and($fresh->status())->toBe(LearnStatus::InProgress);

    // И защита deleteUnstarted видит именно started_at из БД.
    expect(rtRepo()->deleteUnstarted(new UserId($student->id), new LessonId($lesson->id)))
        ->toBeFalse()
        ->and(LearnModel::query()->where('user_id', $student->id)->exists())->toBeTrue();
});

it('завершение не обнуляет startedAt', function () {
    $author = rtUser('author');
    $lesson = rtLesson($author->id);
    $student = rtUser('student');

    $startedAt = new DateTimeImmutable('2025-01-01 10:00:00 UTC');
    $learn = LearnEntity::startNow(
        userId: new UserId($student->id),
        lessonId: new LessonId($lesson->id),
        snapshot: SnapshotHash::fromContent('lesson'),
        questions: domainQuestions(1),
        now: $startedAt,
    )->withId(rtRepo()->reserveId());
    $learn = rtRepo()->insert($learn);

    $completedAt = new DateTimeImmutable('2025-01-02 10:00:00 UTC');
    $learn->markDone(LearnAnswers::empty(), $completedAt);
    $saved = rtRepo()->save($learn);

    $fresh = rtRepo()->getById($saved->id());

    // started_at — факт старта, он не перезаписывается завершением.
    expect($fresh->startedAt()?->getTimestamp())->toBe($startedAt->getTimestamp())
        ->and($fresh->completedAt()?->getTimestamp())->toBe($completedAt->getTimestamp())
        ->and($fresh->status())->toBe(LearnStatus::Done);
});
