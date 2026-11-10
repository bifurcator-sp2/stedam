<?php

declare(strict_types=1);

use App\Application\Learn\Ports\LessonRepositoryInterface;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Infrastructure\Learn\S3SnapshotResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Фейковый репозиторий уроков: отдаёт заданный payload для снапшота.
 */
final class FakeLessonRepo implements LessonRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $payloads = [];

    public int $calls = 0;

    public function __construct(array $payloads = [])
    {
        $this->payloads = $payloads;
    }

    public function isPublished(LessonId $id): bool
    {
        return true;
    }

    public function getForSnapshot(LessonId $id): array
    {
        $this->calls++;

        return $this->payloads[$id->value] ?? ['id' => $id->value, 'title' => 'L'];
    }
}

function resolverWith(FakeLessonRepo $lessons): S3SnapshotResolver
{
    Storage::fake('s3_private');
    Cache::store('array')->clear();

    return new S3SnapshotResolver(
        lessons: $lessons,
        cache: Cache::store('array'),
        filesystems: Storage::getFacadeRoot(),
        disk: 's3_private',
        prefix: 'learns/snapshots',
        cacheTtl: 3600,
        lockTtl: 10,
        lockWait: 5,
    );
}

it('builds hash, stores snapshot on disk, returns content-addressed hash', function () {
    $lessons = new FakeLessonRepo([10 => ['id' => 10, 'title' => 'Hello']]);
    $resolver = resolverWith($lessons);

    $hash = $resolver->ensureFor(new LessonId(10));

    $expected = hash('sha256', (string) json_encode(
        ['id' => 10, 'title' => 'Hello'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ));

    expect($hash->value)->toBe($expected);

    $path = 'learns/snapshots/'.substr($hash->value, 0, 2).'/'.$hash->value.'.json';
    Storage::disk('s3_private')->assertExists($path);
});

it('serves from cache on the second call (no rebuild)', function () {
    $lessons = new FakeLessonRepo();
    $resolver = resolverWith($lessons);

    $resolver->ensureFor(new LessonId(10));
    $resolver->ensureFor(new LessonId(10));

    // Ровно один поход в репозиторий — второй раз из кэша.
    expect($lessons->calls)->toBe(1);
});

it('is idempotent for same content: same hash, single file', function () {
    $lessons = new FakeLessonRepo();
    $resolver = resolverWith($lessons);

    $a = $resolver->ensureFor(new LessonId(10));
    // Сброс кэша, чтобы принудительно пересобрать.
    Cache::store('array')->clear();
    $b = $resolver->ensureFor(new LessonId(10));

    expect($a->value)->toBe($b->value);

    $files = Storage::disk('s3_private')->allFiles('learns/snapshots');
    expect($files)->toHaveCount(1);
});
