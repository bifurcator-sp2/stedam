<?php
// packages/bifurcator/laravel-file-sets/src/Contracts/FileStorage.php

namespace Bifurcator\FileSets\Contracts;

interface FileStorage
{
    public function diskName(): string;

    // ============================================================
    // Чтение
    // ============================================================

    public function exists(string $path): bool;

    public function get(string $path): string;

    public function readStream(string $path);

    public function files(string $dir): array;

    public function mimeType(string $path): ?string;

    public function size(string $path): int;

    // ============================================================
    // Запись
    // ============================================================

    public function put(string $path, string $contents): bool;

    public function writeStream(string $path, $stream): bool;

    public function makeDirectory(string $dir): bool;

    // ============================================================
    // Удаление
    // ============================================================

    public function delete(string $path): bool;

    public function deleteDirectory(string $dir): bool;

    // ============================================================
    // Перемещение и копирование
    // ============================================================

    public function move(string $from, string $to): bool;

    public function copy(string $from, string $to): bool;
}
