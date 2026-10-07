<?php
// app/Files/Processors/ThumbnailProcessor.php

namespace App\Files\Processors;

use App\Files\ThumbnailMaker;
use Illuminate\Support\Facades\Storage;

class ThumbnailProcessor
{
    public function __construct(
        protected ThumbnailMaker $thumbs,
    ) {}

    public function handle($model, array $filenames): void
    {
        if (empty($filenames)) {
            return;
        }

        $storeDir = $model->storeDir();
        $disk = Storage::disk('public');
        $images = $model->imageList();
        $changed = false;

        foreach ($filenames as $filename) {
            $path = "{$storeDir}/{$filename}";

            if (!$disk->exists($path)) {
                continue;
            }

            if (!$this->isImage($path)) {
                continue;
            }

            // Генерим (или переиспользуем) тумб
            $thumbName = $this->thumbs->make($path);
            if (!$thumbName) {
                continue;
            }

            // Прописываем имя тумбнейла в JSON модели
            foreach ($images as &$img) {
                if (($img['original'] ?? null) === $filename) {
                    $img['thumbnail'] = $thumbName;
                    $changed = true;
                }
            }
            unset($img);
        }

        if ($changed) {
            $model->images = $images;
            $model->saveQuietly();
        }
    }

    protected function isImage(string $path): bool
    {
        $mime = Storage::disk('public')->mimeType($path) ?? '';
        return str_starts_with($mime, 'image/');
    }
}
