<?php
// packages/bifurcator/laravel-file-sets/src/Contracts/FileSetContract.php

namespace Bifurcator\FileSets\Contracts;

/**
 * Контракт набора файлов (FileSet).
 *
 * Описывает методы, которые HasFileSets ожидает от модели FileSet.
 * Конкретная реализация может быть заменена через config('file-sets.file_set_model').
 */
interface FileSetContract
{
    /**
     * Список изображений из реестра (JSON).
     */
    public function imageList(): array;

    /**
     * Список обычных файлов из реестра (JSON).
     */
    public function fileList(): array;

    /**
     * Список temp-изображений (сканирование temp-директории).
     */
    public function tempImages(): array;

    /**
     * Список temp-файлов (сканирование temp-директории).
     */
    public function tempFiles(): array;

    /**
     * Список store-изображений (из реестра).
     */
    public function storedImages(): array;

    /**
     * Список store-файлов (из реестра).
     */
    public function storedPlainFiles(): array;
}
