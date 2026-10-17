<?php
// packages/bifurcator/laravel-file-sets/src/Url/S3TemporaryUrlResolver.php

namespace Bifurcator\FileSets\Url;

use Bifurcator\FileSets\Contracts\FileUrlResolver;
use Illuminate\Support\Facades\Storage;

/**
 * Резолвер URL для сценария "temp локально, store в S3".
 *
 * - temp-диск локальный и публичный → обычный Storage::url()
 * - store-диск в S3 и приватный     → временный подписанный URL (temporaryUrl)
 *
 * Время жизни временных ссылок берётся из config('file-sets.temporary_url_ttl').
 */
class S3TemporaryUrlResolver implements FileUrlResolver
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
        )->temporaryUrl(
            $path,
            now()->addSeconds((int) config('file-sets.temporary_url_ttl', 1800)),
        );
    }
}
