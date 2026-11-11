<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\Ports\LessonSnapshotAssemblerInterface;
use App\Application\Learn\Ports\SnapshotResolverInterface;
use App\Domain\Blocks\Settings\SettingsNormalizer;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\SnapshotHash;
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
    private const TTL_SECONDS = 3600;

    public function __construct(
        private LessonSnapshotAssemblerInterface $assembler,
        private Cache $cache,
    ) {}

    public function ensureFor(LessonId $lessonId): SnapshotHash
    {
        $cacheKey = self::CACHE_PREFIX.$lessonId->value;

        $cached = $this->cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return new SnapshotHash($cached);
        }

        $payload = $this->assembler->assemble($lessonId);
        $hash = SnapshotHash::fromContent(SettingsNormalizer::canonicalJson($payload));

        $this->cache->put($cacheKey, $hash->value, self::TTL_SECONDS);

        return $hash;
    }
}
