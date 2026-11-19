<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\Exceptions\SnapshotUnavailable;
use App\Application\Learn\Ports\SnapshotReaderInterface;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use Bifurcator\FileSets\Contracts\FileStorage;
use Bifurcator\FileSets\FileStorageKeys;

/**
 * Чтение JSON снапшота со store-диска FileSets.
 *
 * Снапшоты пишутся через FileStorageKeys::STORE (тот же store-диск,
 * что и вложения блоков — s3_public), путь контент-адресный:
 *   {prefix}/{shard(2)}/{sha256}.json
 * Формат пути обязан совпадать с писателем (S3SnapshotResolver::pathFor),
 * иначе получим 404 при существующем снапшоте.
 */
final readonly class FileStorageSnapshotReader implements SnapshotReaderInterface
{
    public function __construct(
        private string $prefix,
    ) {}

    public function read(SnapshotHash $hash): string
    {
        $path = sprintf('%s/%s/%s.json', trim($this->prefix, '/'), $hash->shard(), $hash->value);

        /** @var FileStorage $storage */
        $storage = app(FileStorageKeys::STORE);

        if (!$storage->exists($path)) {
            throw new SnapshotUnavailable("Snapshot {$hash->value} not found");
        }

        return $storage->get($path);
    }
}
