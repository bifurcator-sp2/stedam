<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\Ports\LessonRepositoryInterface;
use App\Application\Learn\Ports\SnapshotResolverInterface;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Factory as FilesystemManager;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Боевой резолвер снапшота урока.
 *
 * Поток (Redis → S3 → генерация под lock):
 *   1. Redis: lessonId → hash. Есть — вернуть (hot path).
 *   2. Промах — берём distributed lock по lessonId.
 *   3. Под локом повторно проверяем кэш (double-check).
 *   4. Сериализуем урок, считаем SHA-256.
 *   5. Кладём содержимое в S3 (приватный диск) по адресу {prefix}/{hash}.json,
 *      если ещё не лежит (идемпотентно).
 *   6. Кэшируем lessonId → hash, возвращаем.
 *
 * Контент-адресация: одинаковое содержимое = один файл.
 * Ошибки S3 не глотаем: снапшот обязателен для in_progress (инвариант домена),
 * поэтому невозможность сохранить снапшот = отказ операции.
 */
final readonly class S3SnapshotResolver implements SnapshotResolverInterface
{
    private const CACHE_PREFIX = 'learn:lesson_snapshot:';
    private const LOCK_PREFIX = 'learn:lesson_snapshot:lock:';

    public function __construct(
        private LessonRepositoryInterface $lessons,
        private Cache $cache,
        private FilesystemManager $filesystems,
        private string $disk,
        private string $prefix,
        private int $cacheTtl,
        private int $lockTtl,
        private int $lockWait,
    ) {}

    public function ensureFor(LessonId $lessonId): SnapshotHash
    {
        // 1. Fast path — Redis.
        $cached = $this->fromCache($lessonId);
        if ($cached !== null) {
            return $cached;
        }

        // 2-3. Lock + double-check.
        $lock = $this->cache->lock(
            self::LOCK_PREFIX.$lessonId->value,
            $this->lockTtl,
        );

        try {
            $lock->block($this->lockWait, function () use ($lessonId): void {
                // завершаем всё внутри лок-хелпера через замыкание,
                // но результат нам нужен снаружи — поэтому пишем в кэш,
                // и после блока читаем оттуда.
                if ($this->fromCache($lessonId) !== null) {
                    return; // кто-то уже создал, пока ждали лок
                }

                $hash = $this->buildAndStore($lessonId);

                $this->cache->put(
                    self::CACHE_PREFIX.$lessonId->value,
                    $hash->value,
                    $this->cacheTtl,
                );
            });
        } catch (LockTimeoutException) {
            // Не дождались лока — пробуем обойтись тем, что есть.
            $cached = $this->fromCache($lessonId);
            if ($cached !== null) {
                return $cached;
            }
            // Лок не взяли и кэш пуст — строим без лока (лучше так, чем упасть).
            $hash = $this->buildAndStore($lessonId);
            $this->cache->put(self::CACHE_PREFIX.$lessonId->value, $hash->value, $this->cacheTtl);

            return $hash;
        }

        // После успешного блока значение гарантированно в кэше.
        $cached = $this->fromCache($lessonId);
        if ($cached === null) {
            // На всякий случай — аварийная ветка.
            $cached = $this->buildAndStore($lessonId);
        }

        return $cached;
    }

    private function fromCache(LessonId $lessonId): ?SnapshotHash
    {
        $value = $this->cache->get(self::CACHE_PREFIX.$lessonId->value);

        return is_string($value) && $value !== '' ? new SnapshotHash($value) : null;
    }

    /**
     * Сериализует урок, считает хэш, идемпотентно кладёт в S3.
     */
    private function buildAndStore(LessonId $lessonId): SnapshotHash
    {
        $payload = $this->lessons->getForSnapshot($lessonId);
        $content = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $hash = SnapshotHash::fromContent($content);

        $this->disk()->put($this->pathFor($hash), $content);

        return $hash;
    }

    private function pathFor(SnapshotHash $hash): string
    {
        // Шардирование по 2 символам — как принято для контент-адресации.
        return sprintf('%s/%s/%s.json', trim($this->prefix, '/'), $hash->shard(), $hash->value);
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk($this->disk);
    }
}
