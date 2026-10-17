<?php
// packages/bifurcator/laravel-file-sets/src/Contracts/FileUrlResolver.php

namespace Bifurcator\FileSets\Contracts;

/**
 * Резолвер URL для файлов.
 *
 * Разделяет temp- и store-диски, потому что они могут жить
 * на разных дисках (например, temp — локально, store — S3).
 */
interface FileUrlResolver
{
    /**
     * URL для файла на temp-диске.
     *
     * @param string $path Путь относительно temp-диска,
     *                     например "temp/users/1/models/lesson_blocks/5/photo.jpg"
     */
    public function tempUrl(string $path): string;

    /**
     * URL для файла на store-диске.
     *
     * @param string $path Путь относительно store-диска,
     *                     например "models/lesson_blocks/ab/cd/5/photo.jpg"
     */
    public function storeUrl(string $path): string;
}
