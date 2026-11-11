<?php

declare(strict_types=1);

use App\Application\Learn\Ports\LessonSnapshotAssemblerInterface;
use App\Domain\Blocks\Settings\SettingsNormalizer;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Infrastructure\Learn\S3SnapshotResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Fakes\FakeSnapshotAssembler;

function resolverWith(FakeSnapshotAssembler $assembler): S3SnapshotResolver
{
    Storage::fake('s3_private');
    Cache::store('array')->clear();

    return new S3SnapshotResolver(
        assembler: $assembler,
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
    $assembler = new FakeSnapshotAssembler([10 => ['id' => 10, 'title' => 'Hello']]);
    $resolver = resolverWith($assembler);

    $hash = $resolver->ensureFor(new LessonId(10));

    $expected = hash('sha256', SettingsNormalizer::canonicalJson(['id' => 10, 'title' => 'Hello']));

    expect($hash->value)->toBe($expected);

    $path = 'learns/snapshots/'.substr($hash->value, 0, 2).'/'.$hash->value.'.json';
    Storage::disk('s3_private')->assertExists($path);
});

it('serves from cache on the second call (no rebuild)', function () {
    $assembler = new FakeSnapshotAssembler();
    $resolver = resolverWith($assembler);

    $resolver->ensureFor(new LessonId(10));
    $resolver->ensureFor(new LessonId(10));

    // Ровно один поход в ассемблер — второй раз из кэша.
    expect($assembler->calls)->toBe(1);
});

it('is idempotent for same content: same hash, single file', function () {
    $assembler = new FakeSnapshotAssembler();
    $resolver = resolverWith($assembler);

    $a = $resolver->ensureFor(new LessonId(10));
    // Сброс кэша, чтобы принудительно пересобрать.
    Cache::store('array')->clear();
    $b = $resolver->ensureFor(new LessonId(10));

    expect($a->value)->toBe($b->value);

    $files = Storage::disk('s3_private')->allFiles('learns/snapshots');
    expect($files)->toHaveCount(1);
});

it('is stable regardless of key order in the payload', function () {
    // Кириллица/порядок ключей не должны «плясать» в хэше: canonicalJson
    // сортирует ключи. Это защищает §14.17 (одинаковый snap для всех).
    $first = new FakeSnapshotAssembler([10 => ['title' => 'Урок', 'id' => 10]]);
    $second = new FakeSnapshotAssembler([10 => ['id' => 10, 'title' => 'Урок']]);

    $a = resolverWith($first)->ensureFor(new LessonId(10));
    Cache::store('array')->clear();
    $b = resolverWith($second)->ensureFor(new LessonId(10));

    expect($a->value)->toBe($b->value);
});
