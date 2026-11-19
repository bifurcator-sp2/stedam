<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\Ports\SnapshotReaderInterface;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use Bifurcator\FileSets\FileStorageKeys;
use Illuminate\Contracts\Filesystem\Factory as FilesystemManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

/**
 * Боевое чтение JSON снапшота урока по хэшу.
 *
 * Источник правды — приватный диск (learn.snapshot.disk = s3_private),
 * путь тот же, что пишет S3SnapshotResolver: {prefix}/{shard}/{hash}.json.
 *
 * Fallback: S3SnapshotResolver пишет через store-диск FileSets
 * (s3_public) — читаем оттуда, если файла нет на приватном диске.
 * Так новые снапшоты (после унификации) и уже загруженные работают
 * одинаково.
 */
final readonly class S3SnapshotReader implements SnapshotReaderInterface
{
    public function __construct(
        private FilesystemManager $filesystems,
        private string $disk,
        private string $prefix,
    ) {}

    public function read(SnapshotHash $hash): string
    {
        $path = sprintf('%s/%s/%s.json', trim($this->prefix, '/'), $hash->shard(), $hash->value);

        $disk = $this->disk();
        if ($disk->exists($path)) {
            return $disk->get($path);
        }

        $storeDiskName = (string) config('file-sets.store_disk', 'public');
        if ($storeDiskName !== $this->disk) {
            $storeDisk = $this->filesystems->disk($storeDiskName);
            if ($storeDisk->exists($path)) {
                Log::warning('[S3SnapshotReader] snapshot found on store disk, not private', [
                    'path' => $path,
                ]);

                return $storeDisk->get($path);
            }
        }

        throw new \App\Application\Learn\Exceptions\SnapshotUnavailable(
            "Snapshot {$hash->value} not found",
        );
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk($this->disk);
    }
}
