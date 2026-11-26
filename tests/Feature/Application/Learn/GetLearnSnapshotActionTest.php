<?php

declare(strict_types=1);

use App\Application\Learn\Actions\GetLearnSnapshotAction;
use App\Application\Learn\Context\ViewerContext;
use App\Application\Learn\Exceptions\SnapshotUnavailable;
use App\Application\Learn\Ports\SnapshotReaderInterface;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\LearnAnswers;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Domain\Learn\ValueObjects\UserId;
use Tests\Support\Fakes\FakeSnapshotResolver;
use Tests\Support\Fakes\InMemoryLearnRepository;
use Tests\Support\Fakes\StubContextResolver;

/**
 * Снапшот «на диске»: файлы по хэшу. Чтение отсутствующего файла — как у
 * боевого резолвера, SnapshotUnavailable.
 */
final class SnapshotBag
{
    /** @var array<string, string> */
    public array $files = [];

    public function reader(): SnapshotReaderInterface
    {
        return new class ($this) implements SnapshotReaderInterface {
            public function __construct(private readonly SnapshotBag $bag) {}

            public function read(SnapshotHash $hash): string
            {
                return $this->bag->files[$hash->value]
                    ?? throw new SnapshotUnavailable("No snapshot {$hash->value}");
            }
        };
    }
}

/**
 * Learn ученика в статусе in_progress с зафиксированным снапшотом.
 */
function snapshotLearn(InMemoryLearnRepository $learns, int $studentId, int $lessonId, SnapshotHash $hash): Learn
{
    $now = new DateTimeImmutable('2025-01-01 12:00:00 UTC');

    return $learns->insert(Learn::reconstitute(
        id: $learns->reserveId(),
        userId: new UserId($studentId),
        lessonId: new LessonId($lessonId),
        status: LearnStatus::InProgress,
        snapshot: $hash,
        grade: null,
        gradedBy: null,
        scheduleDate: null,
        answers: LearnAnswers::empty(),
        createdAt: $now,
        archivedAt: null,
        statusDate: $now,
        completedAt: null,
        startedAt: $now,
    ));
}

function snapshotAction(
    InMemoryLearnRepository $learns,
    SnapshotReaderInterface $reader,
    \App\Application\Learn\Ports\SnapshotResolverInterface $resolver,
): GetLearnSnapshotAction {
    return new GetLearnSnapshotAction(
        learns: $learns,
        snapshots: $reader,
        resolver: $resolver,
        contextResolver: new StubContextResolver([]),
    );
}

it('returns snapshot JSON as-is', function () {
    $snapshotJson = json_encode([
        'lesson_id'   => 10,
        'title'       => 'L',
        'description' => 'Описание из снапшота',
        'type'        => 'class_work',
        'blocks'      => [],
    ]);

    $hash = SnapshotHash::fromContent($snapshotJson);

    $learns = new InMemoryLearnRepository();
    $learn = snapshotLearn($learns, 7, 10, $hash);

    $bag = new SnapshotBag();
    $bag->files[$hash->value] = $snapshotJson;

    $data = snapshotAction($learns, $bag->reader(), new FakeSnapshotResolver())
        (new LearnId($learn->id()->value), new UserId(7));

    expect($data->snapshotDataArray())
        ->toMatchArray([
            'lesson_id'   => 10,
            'description' => 'Описание из снапшота',
            'type'        => 'class_work',
        ]);
});

it('restores a deleted snapshot file when the lesson is unchanged', function () {
    $hash = SnapshotHash::fromContent('lesson-payload');

    $learns = new InMemoryLearnRepository();
    $learn = snapshotLearn($learns, 7, 10, $hash);

    // Файла нет; урок не менялся — резолвер пересоздаёт его в тот же хэш.
    $bag = new SnapshotBag();

    $resolver = new class ($hash, $bag) implements \App\Application\Learn\Ports\SnapshotResolverInterface {
        public function __construct(
            private readonly SnapshotHash $hash,
            private readonly SnapshotBag $bag,
        ) {}

        public function ensureFor(LessonId $lessonId): SnapshotHash
        {
            return $this->hash;
        }

        public function forceEnsureFor(LessonId $lessonId): SnapshotHash
        {
            $this->bag->files[$this->hash->value] = '{"lesson_id":10}';

            return $this->hash;
        }

        public function lessonFor(SnapshotHash $hash): LessonId
        {
            return new LessonId(10);
        }
    };

    $data = snapshotAction($learns, $bag->reader(), $resolver)
        (new LearnId($learn->id()->value), new UserId(7));

    expect(json_decode($data->snapshotData, true))->toBe(['lesson_id' => 10]);
});

it('does not serve a different version when the lesson changed since start', function () {
    $hash = SnapshotHash::fromContent('lesson-payload');

    $learns = new InMemoryLearnRepository();
    $learn = snapshotLearn($learns, 7, 10, $hash);

    // Пересборка даёт другой хэш: урок изменился после старта.
    $changed = SnapshotHash::fromContent('lesson-payload-changed');
    $bag = new SnapshotBag();

    $resolver = new class ($changed, $bag) implements \App\Application\Learn\Ports\SnapshotResolverInterface {
        public function __construct(
            private readonly SnapshotHash $changed,
            private readonly SnapshotBag $bag,
        ) {}

        public function ensureFor(LessonId $lessonId): SnapshotHash
        {
            return $this->changed;
        }

        public function forceEnsureFor(LessonId $lessonId): SnapshotHash
        {
            $this->bag->files[$this->changed->value] = '{"lesson_id":10}';

            return $this->changed;
        }

        public function lessonFor(SnapshotHash $hash): LessonId
        {
            return new LessonId(10);
        }
    };

    expect(fn () => snapshotAction($learns, $bag->reader(), $resolver)
        (new LearnId($learn->id()->value), new UserId(7)))
        ->toThrow(SnapshotUnavailable::class);
});

it('throws LearnNotFound for a learn outside the viewer scope', function () {
    $hash = SnapshotHash::fromContent('lesson-payload');

    $learns = new InMemoryLearnRepository();
    $learn = snapshotLearn($learns, 7, 10, $hash);

    $action = new GetLearnSnapshotAction(
        learns: $learns,
        snapshots: (new SnapshotBag())->reader(),
        resolver: new FakeSnapshotResolver(),
        contextResolver: new StubContextResolver([
            8 => ViewerContext::readOnly(8, [], [8]),
        ]),
    );

    expect(fn () => $action(new LearnId($learn->id()->value), new UserId(8)))
        ->toThrow(LearnNotFound::class);
});
