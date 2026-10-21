<?php
// packages/bifurcator/laravel-file-sets/src/FilesService.php

namespace Bifurcator\FileSets;

use Bifurcator\FileSets\Contracts\FileStorage;
use Bifurcator\FileSets\Contracts\FileUrlResolver;
use Bifurcator\FileSets\Contracts\ThumbnailMakerContract;

class FilesService
{
    public function __construct(
        protected FileUrlResolver $urlResolver,
        protected ThumbnailMakerContract $thumbs,
    ) {}

    // ============================================================
    // PRELOAD
    // ============================================================

    /**
     * Загружает файлы во временное хранилище.
     *
     * ВАЖНО: Файлы остаются в temp до момента сохранения сущности.
     */
    public function preload(
        array $files,
        string $modelClass,
        int $modelId,
        ?string $purpose = null,
        array $rules = [],
    ): array {
        $tempStorage = app(FileStorageKeys::TEMP);

        if ($purpose !== null) {
            $dir = $this->resolveFileSet($modelClass, $modelId, $purpose)->tempDir();
        } else {
            $userId = auth()->id() ?? 0;
            $modelName = (new $modelClass)->getTable();
            $dir = "temp/users/{$userId}/models/{$modelName}/{$modelId}";
        }

        $tempStorage->makeDirectory($dir);

        $uploadedNames = [];

        foreach ($files as $file) {
            $mime = $file->getMimeType();

            $allowed = array_merge(
                $rules['image_mimes'] ?? [],
                $rules['file_mimes'] ?? [],
            );

            if (!empty($allowed) && !in_array($mime, $allowed, true)) {
                throw new \InvalidArgumentException("Тип файла {$mime} не разрешён.");
            }

            $filename = $file->hashName();
            $file->storeAs($dir, $filename, $tempStorage->diskName());

            if (str_starts_with($mime, 'image/')) {
                $this->thumbs->make("{$dir}/{$filename}");
            }

            $uploadedNames[$filename] = true;
        }

        // Файлы физически остаются в папке temp FileSet'а.
        // Возвращаем их со статусом 'temp'.
        $scanned = $this->scanTemp($dir, $tempStorage);

        return $this->filterByName($scanned, $uploadedNames);
    }

    /**
     * Оставляет только элементы, у которых basename(url) есть в $names.
     */
    protected function filterByName(array $items, array $names): array
    {
        if (empty($names)) {
            return [];
        }

        return array_values(array_filter(
            $items,
            function ($item) use ($names) {
                $url = $item['url'] ?? null;
                if (!$url) {
                    return false;
                }

                $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);

                return isset($names[$name]);
            },
        ));
    }

    // ============================================================
    // LIST
    // ============================================================

    /**
     * Список файлов модели (temp + store).
     */
    public function list(
        string $modelClass,
        int $modelId,
        ?string $purpose = null,
    ): array {
        if ($purpose !== null) {
            $fileSet = $this->findFileSet($modelClass, $modelId, $purpose);

            if (!$fileSet) {
                $fileSet = $this->newFileSetStub($modelClass, $modelId, $purpose);
            }

            $tempImages = $fileSet->tempImages();
            $tempFiles  = $fileSet->tempFiles();

            $storedImages = $fileSet->exists ? $fileSet->storedImages() : [];
            $storedFiles  = $fileSet->exists ? $fileSet->storedPlainFiles() : [];

            return $this->formatList($tempImages, $tempFiles, $storedImages, $storedFiles);
        }

        // Старое поведение (без purpose)
        $model = $modelClass::find($modelId);

        $userId = auth()->id() ?? 0;
        $modelName = (new $modelClass)->getTable();
        $tempDir = "temp/users/{$userId}/models/{$modelName}/{$modelId}";

        $tempStorage = app(FileStorageKeys::TEMP);

        $tempImages = $this->scanTemp($tempDir, $tempStorage, true);
        $tempFiles  = $this->scanTemp($tempDir, $tempStorage, false);

        $storedImages = $model ? $model->storedImages() : [];
        $storedFiles  = $model ? $model->storedPlainFiles() : [];

        return $this->formatList($tempImages, $tempFiles, $storedImages, $storedFiles);
    }

    // ============================================================
    // DELETE TEMP
    // ============================================================

    /**
     * Удаляет временный файл.
     */
    public function deleteTemp(
        string $modelClass,
        int $modelId,
        string $url,
        ?string $purpose = null,
    ): bool {
        $tempStorage = app(FileStorageKeys::TEMP);

        $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if (!$name) {
            return false;
        }

        if ($purpose !== null) {
            $fileSet = $this->findFileSet($modelClass, $modelId, $purpose);

            if ($fileSet) {
                if (collect($fileSet->imageList())->pluck('original')->contains($name)) {
                    $fileSet->deleteStoredImage($url);
                    return true;
                }

                if (collect($fileSet->fileList())->pluck('name')->contains($name)) {
                    $fileSet->deleteStoredFile($url);
                    return true;
                }
            }

            $dir = $fileSet
                ? $fileSet->tempDir()
                : "temp/users/" . (auth()->id() ?? 0)
                . "/models/" . (new $modelClass)->getTable()
                . "/{$modelId}";
        } else {
            $userId = auth()->id() ?? 0;
            $modelName = (new $modelClass)->getTable();
            $dir = "temp/users/{$userId}/models/{$modelName}/{$modelId}";
        }

        $path = "{$dir}/{$name}";

        if (!$tempStorage->exists($path)) {
            return false;
        }

        $tempStorage->delete($path);

        $thumbPath = "{$dir}/thumb_{$name}";
        if ($tempStorage->exists($thumbPath)) {
            $tempStorage->delete($thumbPath);
        }

        return true;
    }

    // ============================================================
    // ВНУТРЕННИЕ ХЕЛПЕРЫ
    // ============================================================

    protected function resolveFileSet(string $modelClass, int $modelId, string $purpose)
    {
        $class = config('file-sets.file_set_model');

        return $class::firstOrCreate([
            'model'    => $modelClass,
            'model_id' => $modelId,
            'purpose'  => $purpose,
        ]);
    }

    protected function findFileSet(string $modelClass, int $modelId, string $purpose)
    {
        $model = $modelClass::find($modelId);

        return $model?->findFileSet($purpose);
    }

    protected function newFileSetStub(string $modelClass, int $modelId, string $purpose)
    {
        $class = config('file-sets.file_set_model');

        return new $class([
            'model'    => $modelClass,
            'model_id' => $modelId,
            'purpose'  => $purpose,
        ]);
    }

    protected function formatList(
        array $tempImages,
        array $tempFiles,
        array $storedImages,
        array $storedFiles,
    ): array {
        return [
            'images' => array_merge($tempImages, $storedImages),
            'files'  => array_merge($tempFiles, $storedFiles),
            'temp'   => [
                'images' => $tempImages,
                'files'  => $tempFiles,
            ],
            'stored' => [
                'images' => $storedImages,
                'files'  => $storedFiles,
            ],
        ];
    }

    protected function scanTemp(
        string $dir,
        FileStorage $storage,
        ?bool $imagesOnly = null,
    ): array {
        if (!$storage->exists($dir)) {
            return [];
        }

        $result = [];

        foreach ($storage->files($dir) as $path) {
            $filename = basename($path);

            if (str_starts_with($filename, 'thumb_')) {
                continue;
            }

            $mime = $storage->mimeType($path) ?? '';
            $isImg = str_starts_with($mime, 'image/');

            if ($imagesOnly !== null && $imagesOnly !== $isImg) {
                continue;
            }

            $url = $this->urlResolver->tempUrl($path);

            if ($isImg) {
                $thumbName = $this->findThumbName($path, $storage);
                $result[] = [
                    'url'       => $url,
                    'thumbnail' => $thumbName
                        ? $this->urlResolver->tempUrl(pathinfo($path, PATHINFO_DIRNAME) . '/' . $thumbName)
                        : null,
                    'source'    => 'temp',
                    'title'     => null,
                ];
            } else {
                $result[] = [
                    'url'    => $url,
                    'source' => 'temp',
                    'title'  => null,
                ];
            }
        }

        return $result;
    }

    protected function findThumbName(string $path, FileStorage $storage): ?string
    {
        $dir  = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $ext  = pathinfo($path, PATHINFO_EXTENSION);

        $thumbName = "thumb_{$name}.{$ext}";

        return $storage->exists("{$dir}/{$thumbName}") ? $thumbName : null;
    }
}
