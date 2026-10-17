<?php
// packages/bifurcator/laravel-file-sets/src/Storage/LaravelFileStorage.php

namespace Bifurcator\FileSets\Storage;

use Bifurcator\FileSets\Contracts\FileStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Реализация FileStorage поверх Laravel Storage.
 *
 * Ничего не знает про конкретный диск — работает с тем,
 * чьё имя передано в конструктор.
 */
class LaravelFileStorage implements FileStorage
{
    public function __construct(
        protected string $disk,
    ) {}

    // ============================================================
    // Служебное
    // ============================================================

    public function diskName(): string
    {
        return $this->disk;
    }

    protected function fs(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    // ============================================================
    // Чтение
    // ============================================================

    public function exists(string $path): bool
    {
        return $this->fs()->exists($path);
    }

    public function get(string $path): string
    {
        return $this->fs()->get($path);
    }

    public function readStream(string $path)
    {
        return $this->fs()->readStream($path);
    }

    public function files(string $dir): array
    {
        return $this->fs()->files($dir);
    }

    public function mimeType(string $path): ?string
    {
        return $this->fs()->mimeType($path) ?: null;
    }

    public function size(string $path): int
    {
        return $this->fs()->size($path);
    }

    // ============================================================
    // Запись
    // ============================================================

    public function put(string $path, string $contents): bool
    {
        return $this->fs()->put($path, $contents);
    }

    public function writeStream(string $path, $stream): bool
    {
        return $this->fs()->writeStream($path, $stream);
    }

    public function makeDirectory(string $dir): bool
    {
        return $this->fs()->makeDirectory($dir);
    }

    // ============================================================
    // Удаление
    // ============================================================

    public function delete(string $path): bool
    {
        return $this->fs()->delete($path);
    }

    public function deleteDirectory(string $dir): bool
    {
        return $this->fs()->deleteDirectory($dir);
    }

    // ============================================================
    // Перемещение и копирование
    // ============================================================

    public function move(string $from, string $to): bool
    {
        return $this->fs()->move($from, $to);
    }

    public function copy(string $from, string $to): bool
    {
        return $this->fs()->copy($from, $to);
    }
}
