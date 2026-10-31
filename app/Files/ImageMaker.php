<?php
// app/Files/ImageMaker.php

namespace App\Files;

use Bifurcator\FileSets\Contracts\FileStorage;
use Bifurcator\FileSets\Contracts\ImageMakerContract;
use Bifurcator\FileSets\FileStorageKeys;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Laravel\Facades\Image;

class ImageMaker implements ImageMakerContract
{
    protected int $maxWidth;
    protected bool $allowUpscale;
    protected int $quality;
    protected string $prefix = ''; // не используется, но для симметрии

    /** Форматы, которые безопасно перекодировать. GIF (анимация) и SVG (вектор) — вон. */
    protected array $supportedMimes = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        ?int $maxWidth = null,
        ?bool $allowUpscale = null,
        ?int $quality = null,
    ) {
        $this->maxWidth     = $maxWidth     ?? (int)  config('file-sets.image_width', 1024);
        $this->allowUpscale = $allowUpscale ?? (bool) config('file-sets.image_upscale', false);
        $this->quality      = $quality      ?? (int)  config('file-sets.image_quality', 85);
    }

    public function make(string $sourcePath, ?FileStorage $storage = null): ?string
    {
        $storage ??= app(FileStorageKeys::TEMP);

        if (!$storage->exists($sourcePath)) {
            Log::warning('[ImageMaker] source missing', ['path' => $sourcePath]);
            return null;
        }

        $mime = $storage->mimeType($sourcePath) ?? '';

        if (!in_array($mime, $this->supportedMimes, true)) {
            // SVG, GIF, TIFF, BMP — оставляем как есть
            return null;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        try {
            $image = Image::decode($storage->get($sourcePath));
            $currentWidth = $image->width();

            // Upscale разрешён → всегда приводим к maxWidth.
            // Upscale запрещён → режем только если шире maxWidth.
            $needsResize = $this->allowUpscale
                ? $currentWidth !== $this->maxWidth
                : $currentWidth > $this->maxWidth;

            if (!$needsResize) {
                // Уже в пределах — не трогаем, чтобы не терять качество
                return basename($sourcePath);
            }

            // scale() сохраняет пропорции.
            // Для allowUpscale=true, если scale() не апскейлит в вашей версии Intervention,
            // замените на ->scaleUp(width: $this->maxWidth) или ->resize(width: ..., height: null).
            $image->scale(width: $this->maxWidth);

            $encoded = (string) $image->encodeUsingFileExtension(
                $ext,
                quality: $this->quality,
            );

            // Перезапись по тому же пути — filename не меняется
            $storage->put($sourcePath, $encoded);

            return basename($sourcePath);
        } catch (\Throwable $e) {
            Log::warning('[ImageMaker] failed', [
                'path'    => $sourcePath,
                'mime'    => $mime,
                'width'   => $this->maxWidth,
                'quality' => $this->quality,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }
}
