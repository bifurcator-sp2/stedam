<?php
// app/Files/ThumbnailMaker.php

namespace App\Files;

use Bifurcator\FileSets\Contracts\FileStorage;
use Bifurcator\FileSets\Contracts\ThumbnailMakerContract;
use Bifurcator\FileSets\FileStorageKeys;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Laravel\Facades\Image;

class ThumbnailMaker implements ThumbnailMakerContract
{
    protected int $width;
    protected int $quality;
    protected string $prefix = 'thumb_';

    /**
     * @param int|null $width   Переопределяет config('file-sets.thumbnail_width')
     * @param int|null $quality Переопределяет config('file-sets.thumbnail_quality')
     */
    public function __construct(?int $width = null, ?int $quality = null)
    {
        $this->width   = $width   ?? (int) config('file-sets.thumbnail_width', 300);
        $this->quality = $quality ?? (int) config('file-sets.thumbnail_quality', 85);
    }

    /**
     * Генерирует тумбнейл рядом с оригиналом.
     * Возвращает имя тумбнейла или null.
     *
     * @param string $sourcePath путь относительно диска
     * @param FileStorage|null $storage хранилище; если null — берётся TEMP
     */
    public function make(string $sourcePath, ?FileStorage $storage = null): ?string
    {
        $storage ??= app(FileStorageKeys::TEMP);

        if (!$storage->exists($sourcePath)) {
            Log::warning('[ThumbnailMaker] source missing', ['path' => $sourcePath]);
            return null;
        }

        $dir  = pathinfo($sourcePath, PATHINFO_DIRNAME);
        $base = pathinfo($sourcePath, PATHINFO_FILENAME);
        $ext  = pathinfo($sourcePath, PATHINFO_EXTENSION);

        $thumbName = "{$this->prefix}{$base}.{$ext}";
        $thumbPath = "{$dir}/{$thumbName}";

        // Уже есть — не пересобираем
        if ($storage->exists($thumbPath)) {
            return $thumbName;
        }

        try {
            // Читаем бинарник через storage — работает и на local, и на S3
            $image = Image::decode($storage->get($sourcePath))
                ->scale(width: $this->width);

            $encoded = (string) $image->encodeUsingFileExtension(
                $ext,
                quality: $this->quality,
            );

            $storage->put($thumbPath, $encoded);

            return $thumbName;
        } catch (\Throwable $e) {
            Log::warning('[ThumbnailMaker] failed', [
                'path'    => $sourcePath,
                'width'   => $this->width,
                'quality' => $this->quality,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }
}
