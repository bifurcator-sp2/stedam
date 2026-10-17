<?php
// packages/bifurcator/laravel-file-sets/src/Url/LocalUrlResolver.php

namespace Bifurcator\FileSets\Url;

use Bifurcator\FileSets\Contracts\FileUrlResolver;
use Illuminate\Support\Facades\Storage;

/**
 * Резолвер URL для локальных дисков.
 *
 * temp- и store-диски могут быть разными (например, temp = public,
 * store = public), но оба локальные и публичные.
 *
 * Использует обычный Storage::url() для обоих дисков.
 */
class LocalUrlResolver implements FileUrlResolver
{
    public function tempUrl(string $path): string
    {
        return Storage::disk(
            config('file-sets.temp_disk', 'public')
        )->url($path);
    }

    public function storeUrl(string $path): string
    {
        return Storage::disk(
            config('file-sets.store_disk', 'public')
        )->url($path);
    }
}
