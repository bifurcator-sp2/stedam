<?php

namespace Bifurcator\FileSets\Support;

use Bifurcator\FileSets\Contracts\FileStorage;
use Bifurcator\FileSets\Contracts\ImageMakerContract;

/**
 * Заглушка: ничего не делает. Файл остаётся как загрузили.
 */
class NullImageMaker implements ImageMakerContract
{
    public function make(string $sourcePath, ?FileStorage $storage = null): ?string
    {
        return null;
    }
}
