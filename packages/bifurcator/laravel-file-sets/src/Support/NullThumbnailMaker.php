<?php
// packages/bifurcator/laravel-file-sets/src/Support/NullThumbnailMaker.php

namespace Bifurcator\FileSets\Support;

use Bifurcator\FileSets\Contracts\FileStorage;
use Bifurcator\FileSets\Contracts\ThumbnailMakerContract;

/**
 * Заглушка: не генерирует thumbnail'ы.
 * Используется, если в конфиге thumbnail_maker не указан.
 */
class NullThumbnailMaker implements ThumbnailMakerContract
{
    public function make(string $sourcePath, ?FileStorage $storage = null): ?string
    {
        return null;
    }
}
