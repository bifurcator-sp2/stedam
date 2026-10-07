<?php
// app/Files/ThumbnailMaker.php

namespace App\Files;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class ThumbnailMaker
{
    protected int $width = 300;
    protected int $quality = 85;
    protected string $prefix = 'thumb_';

    /**
     * Генерирует тумбнейл рядом с оригиналом.
     * Возвращает имя тумбнейла или null.
     *
     * @param string $sourcePath путь относительно диска 'public'
     */
    public function make(string $sourcePath): ?string
    {
        $disk = Storage::disk('public');

        if (!$disk->exists($sourcePath)) {
            Log::warning('[ThumbnailMaker] source missing', ['path' => $sourcePath]);
            return null;
        }

        $dir  = pathinfo($sourcePath, PATHINFO_DIRNAME);
        $base = pathinfo($sourcePath, PATHINFO_FILENAME);
        $ext  = pathinfo($sourcePath, PATHINFO_EXTENSION);

        $thumbName = "{$this->prefix}{$base}.{$ext}";
        $thumbPath = "{$dir}/{$thumbName}";

        // Уже есть — не пересобираем
        if ($disk->exists($thumbPath)) {
            return $thumbName;
        }

        try {
            $image = Image::decode($disk->path($sourcePath))
                ->scale(width: $this->width);

            $encoded = (string) $image->encodeUsingFileExtension(
                $ext,
                quality: $this->quality
            );

            $disk->put($thumbPath, $encoded);

           /* Log::info('[ThumbnailMaker] done', [
                'target' => $thumbPath,
                'bytes'  => strlen($encoded),
            ]);*/

            return $thumbName;
        } catch (\Throwable $e) {
            Log::warning('[ThumbnailMaker] failed', [
                'path'  => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function prefix(): string
    {
        return $this->prefix;
    }
}
