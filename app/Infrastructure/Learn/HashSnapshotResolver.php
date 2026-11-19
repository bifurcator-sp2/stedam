<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\Ports\LessonSnapshotAssemblerInterface;
use App\Application\Learn\Ports\SnapshotResolverInterface;
use App\Domain\Blocks\Settings\SettingsNormalizer;
use App\Domain\Learn\Exceptions\SnapshotMissing;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Models\Lesson;
use Bifurcator\FileSets\Contracts\FileStorage;
use Bifurcator\FileSets\FileStorageKeys;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Простейший резолвер снапшота урока: сериализует урок и берёт SHA-256 хэш.
 *
 * Хэш — идемпотентный «адрес» неизменяемого снапшота. Кэш (Redis в проде)
 * хранит lessonId → hash, чтобы не сериализовать урок повторно.
 *
 * Полноценная версия (S3 + distributed lock + Redis) придёт с отдельным
 * контекстом; контракт порта при этом не изменится.
 */
final readonly class HashSnapshotResolver implements SnapshotResolverInterface
{
    private const CACHE_PREFIX = 'learn:lesson_snapshot:';
    private const LESSON_BY_HASH_PREFIX = 'learn:snapshot_lesson:';
    private const TTL_SECONDS = 3600;

    public function __construct(
        private LessonSnapshotAssemblerInterface $assembler,
        private Cache $cache,
        private Lesson $lessons,
        private string $prefix,
    ) {}

    public function ensureFor(LessonId $lessonId): SnapshotHash
    {
        $cacheKey = self::CACHE_PREFIX.$lessonId->value;

        $cached = $this->cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return new SnapshotHash($cached);
        }

        $hash = $this->build($lessonId);

        $this->cache->put($cacheKey, $hash->value, self::TTL_SECONDS);

        return $hash;
    }

    public function forceEnsureFor(LessonId $lessonId): SnapshotHash
    {
        $cacheKey = self::CACHE_PREFIX.$lessonId->value;

        $cached = $this->cache->get($cacheKey);
        if (
            is_string($cached)
            && $cached !== ''
            && $this->storage()->exists($this->pathFor(new SnapshotHash($cached)))
        ) {
            return new SnapshotHash($cached);
        }

        // Файла нет (или он удалён) — пересобираем и кладём заново.
        $content = SettingsNormalizer::canonicalJson($this->assembler->assemble($lessonId));
        $hash = SnapshotHash::fromContent($content);

        $this->storage()->put($this->pathFor($hash), $content);
        $this->cache->put($cacheKey, $hash->value, self::TTL_SECONDS);

        return $hash;
    }

    public function lessonFor(SnapshotHash $hash): LessonId
    {
        $lessonId = $this->cache->get(self::LESSON_BY_HASH_PREFIX.$hash->value);
        if (is_int($lessonId)) {
            return new LessonId($lessonId);
        }

        /** @var Lesson|null $lesson */
        $lesson = $this->lessons->newQuery()
            ->where('lesson_snapshot', $hash->value)
            ->orderBy('id')
            ->first();

        if ($lesson === null) {
            throw new SnapshotMissing("No lesson references snapshot {$hash->value}");
        }

        $this->cache->put(self::LESSON_BY_HASH_PREFIX.$hash->value, $lesson->id, self::TTL_SECONDS);

        return new LessonId($lesson->id);
    }

    private function build(LessonId $lessonId): SnapshotHash
    {
        $payload = $this->assembler->assemble($lessonId);

        return SnapshotHash::fromContent(SettingsNormalizer::canonicalJson($payload));
    }

    private function pathFor(SnapshotHash $hash): string
    {
        return sprintf('%s/%s/%s.json', trim($this->prefix, '/'), $hash->shard(), $hash->value);
    }

    private function storage(): FileStorage
    {
        return app(FileStorageKeys::STORE);
    }
}
