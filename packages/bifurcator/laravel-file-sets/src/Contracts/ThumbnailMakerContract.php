<?php
// packages/bifurcator/laravel-file-sets/src/Contracts/ThumbnailMakerContract.php

namespace Bifurcator\FileSets\Contracts;

/**
 * Контракт генератора thumbnail'ов.
 *
 * Пакет не знает, как именно создаются миниатюры — он просто
 * вызывает этот интерфейс. Конкретная реализация живёт в проекте
 * (или в отдельном пакете).
 */
interface ThumbnailMakerContract
{
    /**
     * Генерирует thumbnail рядом с оригиналом.
     *
     * @param string $sourcePath Путь относительно диска
     * @param FileStorage|null $storage Хранилище; если null — берётся TEMP
     * @return string|null Имя thumbnail'а или null, если не удалось
     */
    public function make(string $sourcePath, ?FileStorage $storage = null): ?string;
}
