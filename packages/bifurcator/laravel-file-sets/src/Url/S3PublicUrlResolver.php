<?php
// packages/bifurcator/laravel-file-sets/src/Url/S3PublicUrlResolver.php

namespace Bifurcator\FileSets\Url;

use Bifurcator\FileSets\Contracts\FileUrlResolver;
use Illuminate\Support\Facades\Storage;

/**
 * Резолвер URL для сценария "temp локально, store в S3 (публичный)".
 *
 * - temp-диск локальный и публичный → обычный Storage::url()
 * - store-диск в S3 и публичный     → обычный Storage::url() (без подписи)
 *
 * В отличие от S3TemporaryUrlResolver не генерирует временные подписанные
 * ссылки — предполагается, что bucket публичный.
 */
class S3PublicUrlResolver implements FileUrlResolver
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
            config('file-sets.store_disk', 's3')
        )->url($path);
    }
}
