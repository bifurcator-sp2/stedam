<?php
// app/Files/Processors/ThumbnailProcessor.php

namespace App\Files\Processors;

use Bifurcator\FileSets\Contracts\FileStorage;
use Bifurcator\FileSets\Contracts\ThumbnailMakerContract; // <-- ИНТЕРФЕЙС
use Bifurcator\FileSets\FileStorageKeys;

class ThumbnailProcessor
{
    public function __construct(
        protected ThumbnailMakerContract $thumbs, // <-- ИНТЕРФЕЙС
    ) {}

    /**
     * @param mixed $model Модель с трейтом FilesContainer (например, FileSet)
     * @param array $filenames Имена файлов (оригиналов)
     */
    public function handle($model, array $filenames): void
    {
        if (empty($filenames)) {
            return;
        }

        $storage = app(FileStorageKeys::STORE);

        // ВАЖНО: берем storeDir() у самой модели (FileSet),
        // так как файлы лежат в папке этого FileSet, а не родительского блока
        $storeDir = $model->storeDir();

        $images = $model->imageList();
        $changed = false;

        foreach ($filenames as $filename) {
            $path = "{$storeDir}/{$filename}";

            if (!$storage->exists($path)) {
                continue;
            }

            if (!$this->isImage($path, $storage)) {
                continue;
            }

            // Генерим тумб
            $thumbName = $this->thumbs->make($path, $storage);
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

    protected function isImage(string $path, FileStorage $storage): bool
    {
        $mime = $storage->mimeType($path) ?? '';
        return str_starts_with($mime, 'image/');
    }
}
