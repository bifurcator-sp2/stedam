<?php
// packages/bifurcator/laravel-file-sets/src/Url/LocalUrlResolver.php

namespace Bifurcator\FileSets\Url;

use Bifurcator\FileSets\Contracts\FileUrlResolver;
use Illuminate\Support\Facades\Storage;

/**
 * Резолвер URL для локальных дисков.
 *
 * Возвращает ОТНОСИТЕЛЬНЫЕ пути ("/storage/...") — их можно сохранять
 * в settings и переносить проект между доменами.
 *
 * Браузер резолвит такие URL против origin текущей страницы.
 */
class LocalUrlResolver implements FileUrlResolver
{
    public function tempUrl(string $path): string
    {
        return $this->relative(
            Storage::disk(config('file-sets.temp_disk', 'public'))->url($path)
        );
    }

    public function storeUrl(string $path): string
    {
        return $this->relative(
            Storage::disk(config('file-sets.store_disk', 'public'))->url($path)
        );
    }

    /**
     * Срезает origin: "http://host/storage/x.jpg" → "/storage/x.jpg".
     * Если URL уже относительный — возвращает как есть.
     */
    private function relative(string $url): string
    {
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return $url;
        }

        $path = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        return $path;
    }
}
