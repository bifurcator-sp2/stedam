<?php
// packages/bifurcator/laravel-file-sets/src/Contracts/FileStorage.php

namespace Bifurcator\FileSets\Contracts;

/**
 * Обёртка над Storage::disk() для операций с файлами.
 *
 * Отделяет код от конкретного диска (public, local, s3, ...).
 * Все методы работают с путями относительно диска.
 *
 * Не отвечает за формирование URL — это задача FileUrlResolver.
 */
interface FileStorage
{
    /**
     * Имя диска, с которым работает это хранилище.
     * Например: 'public', 'local', 's3'.
     */
    public function diskName(): string;

    // ============================================================
    // Чтение
    // ============================================================

    public function exists(string $path): bool;

    /** Содержимое файла как binary-строка. */
    public function get(string $path): string;

    /** Содержимое файла как поток (для больших файлов). */
    public function readStream(string $path);

    /** Список файлов в директории (без поддиректорий). */
    public function files(string $dir): array;

    /** MIME-тип файла. */
    public function mimeType(string $path): ?string;

    /** Размер в байтах. */
    public function size(string $path): int;

    // ============================================================
    // Запись
    // ============================================================

    public function put(string $path, string $contents): bool;

    /** Запись из потока (для больших файлов). */
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

    /** Перемещение в рамках одного диска. */
    public function move(string $from, string $to): bool;

    /** Копирование в рамках одного диска. */
    public function copy(string $from, string $to): bool;
}
