<?php
// packages/bifurcator/laravel-file-sets/src/Concerns/FilesContainer.php

namespace Bifurcator\FileSets\Concerns;

use Bifurcator\FileSets\Contracts\FileStorage;
use Bifurcator\FileSets\Contracts\FileUrlResolver;
use Bifurcator\FileSets\Exceptions\FileLimitExceededException;
use Bifurcator\FileSets\FileStorageKeys;

trait FilesContainer
{
    /* ============================================================
     *  BOOT
     * ============================================================ */

    public static function bootFilesContainer(): void
    {
        static::created(function ($model) {
            $model->moveTempToStore();
        });

        static::deleting(function ($model) {
            // Soft delete — не трогаем файлы
            if (method_exists($model, 'isForceDeleting') && !$model->isForceDeleting()) {
                return;
            }

            $model->deleteAllFiles();
        });
    }

    /* ============================================================
     *  ХЕЛПЕРЫ
     * ============================================================ */

    protected function urlResolver(): FileUrlResolver
    {
        return app(FileUrlResolver::class);
    }

    protected function tempStorage(): FileStorage
    {
        return app(FileStorageKeys::TEMP);
    }

    protected function storeStorage(): FileStorage
    {
        if (method_exists($this, 'storeDisk')) {
            return new \Bifurcator\FileSets\Storage\LaravelFileStorage($this->storeDisk());
        }
        return app(FileStorageKeys::STORE);
    }

    protected function transferFile(
        FileStorage $from,
        string $fromPath,
        FileStorage $to,
        string $toPath,
        ?string $mimeType = null
    ): void {
        if ($from->diskName() === $to->diskName()) {
            $from->move($fromPath, $toPath);
            return;
        }

        $stream = $from->readStream($fromPath);
        if ($stream === null) {
            throw new \RuntimeException(
                "Не удалось открыть поток для чтения: {$fromPath}"
            );
        }

        try {
            $options = [];
            if ($mimeType) {
                $options['ContentType'] = $mimeType;
            }

            // Безопасный вызов: передаем $options только если метод его принимает
            $method = new \ReflectionMethod($to, 'writeStream');
            if ($method->getNumberOfParameters() >= 3) {
                $to->writeStream($toPath, $stream, $options);
            } else {
                $to->writeStream($toPath, $stream);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $from->delete($fromPath);
    }

    /* ============================================================
     *  ПУТИ
     * ============================================================ */

    public function tempDir(): string
    {
        // FileSet не имеет своего user_id, всегда смотрим на владельца
        $userId = $this->owner?->user_id
            ?? auth()->id()
            ?? 0;

        $modelName = $this->getTable();
        $modelId = $this->getKey() ?: 0;

        return "temp/users/{$userId}/models/{$modelName}/{$modelId}";
    }

    public function storeDir(): string
    {
        if (!$this->getKey()) {
            return "models/{$this->getTable()}/_new";
        }

        return "models/{$this->getTable()}/{$this->shardPrefix()}/{$this->getKey()}";
    }

    /* ============================================================
     *  ... ОСТАЛЬНЫЕ МЕТОДЫ (imageList, fileList, actuate...) БЕЗ ИЗМЕНЕНИЙ ...
     * ============================================================ */

    public function imageList(): array
    {
        return is_array($this->images) ? $this->images : [];
    }

    public function fileList(): array
    {
        return is_array($this->files) ? $this->files : [];
    }

    public function actuateImageList(array $images): array
    {
        if (!$this->getKey() || empty($images)) {
            return $images;
        }

        $storage = $this->storeStorage();
        $storeDir = $this->storeDir();
        $changed = false;
        $filtered = [];

        foreach ($images as $img) {
            $original = $img['original'] ?? null;
            if (!$original) {
                $changed = true;
                continue;
            }

            $originalPath = "{$storeDir}/{$original}";
            if (!$storage->exists($originalPath)) {
                $changed = true;
                continue;
            }

            if (!empty($img['thumbnail'])) {
                $thumbPath = "{$storeDir}/{$img['thumbnail']}";
                if (!$storage->exists($thumbPath)) {
                    $img['thumbnail'] = null;
                    $changed = true;
                }
            }

            $filtered[] = $img;
        }

        if ($changed) {
            $this->images = $filtered ?: null;
            $this->saveQuietly();
        }

        return $filtered;
    }

    public function actuateFileList(array $files): array
    {
        if (!$this->getKey() || empty($files)) {
            return $files;
        }

        $storage = $this->storeStorage();
        $storeDir = $this->storeDir();
        $changed = false;
        $filtered = [];

        foreach ($files as $f) {
            $name = $f['name'] ?? null;
            if (!$name) {
                $changed = true;
                continue;
            }

            $path = "{$storeDir}/{$name}";
            if (!$storage->exists($path)) {
                $changed = true;
                continue;
            }

            $filtered[] = $f;
        }

        if ($changed) {
            $this->files = $filtered ?: null;
            $this->saveQuietly();
        }

        return $filtered;
    }

    /* ============================================================
     *  ПРУНИНГ (Вызывать вручную или через cron)
     * ============================================================ */

    public function pruneOrphanImages(): array
    {
        if (!$this->getKey()) {
            return ['deleted' => [], 'kept' => []];
        }

        $storage = $this->storeStorage();
        $storeDir = $this->storeDir();

        if (!$storage->exists($storeDir)) {
            return ['deleted' => [], 'kept' => []];
        }

        $registryImages = $this->actuateImageList($this->imageList());

        $expectedOriginals = [];
        $expectedThumbs    = [];

        foreach ($registryImages as $img) {
            if (!empty($img['original'])) {
                $expectedOriginals[$img['original']] = true;
            }
            if (!empty($img['thumbnail'])) {
                $expectedThumbs[$img['thumbnail']] = true;
            }
        }

        $deleted = [];
        $kept    = [];

        foreach ($storage->files($storeDir) as $path) {
            $filename = basename($path);

            if ($filename === '.gitignore' || str_starts_with($filename, '.')) {
                continue;
            }

            if (!$this->isImagePath($path, $storage)) {
                continue;
            }

            $isThumb = str_starts_with($filename, 'thumb_');

            if ($isThumb) {
                if (isset($expectedThumbs[$filename])) {
                    $kept[] = $filename;
                    continue;
                }
                $storage->delete($path);
                $deleted[] = $filename;
                continue;
            }

            if (isset($expectedOriginals[$filename])) {
                $kept[] = $filename;
                continue;
            }

            $storage->delete($path);
            $deleted[] = $filename;
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    public function pruneOrphanFiles(): array
    {
        if (!$this->getKey()) {
            return ['deleted' => [], 'kept' => []];
        }

        $storage = $this->storeStorage();
        $storeDir = $this->storeDir();

        if (!$storage->exists($storeDir)) {
            return ['deleted' => [], 'kept' => []];
        }

        $registryFiles = $this->actuateFileList($this->fileList());

        $expected = [];
        foreach ($registryFiles as $f) {
            if (!empty($f['name'])) {
                $expected[$f['name']] = true;
            }
        }

        $deleted = [];
        $kept    = [];

        foreach ($storage->files($storeDir) as $path) {
            $filename = basename($path);

            if ($filename === '.gitignore' || str_starts_with($filename, '.')) {
                continue;
            }

            if (str_starts_with($filename, 'thumb_')) {
                continue;
            }

            if ($this->isImagePath($path, $storage)) {
                continue;
            }

            if (isset($expected[$filename])) {
                $kept[] = $filename;
                continue;
            }

            $storage->delete($path);
            $deleted[] = $filename;
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    public function pruneOrphans(): array
    {
        return [
            'images' => $this->pruneOrphanImages(),
            'files'  => $this->pruneOrphanFiles(),
        ];
    }

    public function pruneEmptyStoreDirs(): array
    {
        $deleted = [];

        if (!$this->getKey()) {
            return ['deleted' => $deleted];
        }

        $storage  = $this->storeStorage();
        $storeDir = $this->storeDir();

        $candidates = [
            $storeDir,
            dirname($storeDir),
            dirname(dirname($storeDir)),
        ];

        foreach ($candidates as $dir) {
            if (!$storage->exists($dir)) {
                continue;
            }

            if (!$this->isDirEmpty($dir, $storage)) {
                break;
            }

            if (!$storage->deleteDirectory($dir)) {
                break;
            }

            $deleted[] = $dir;
        }

        return ['deleted' => $deleted];
    }

    protected function isDirEmpty(string $dir, FileStorage $storage): bool
    {
        if (!empty($storage->files($dir))) {
            return false;
        }

        if (!empty($storage->directories($dir))) {
            return false;
        }

        return true;
    }

    public function scanDir(string $dir, bool $imagesOnly, string $source): array
    {
        $storage = $this->tempStorage();

        if (!$storage->exists($dir)) {
            return [];
        }

        $urlResolver = $this->urlResolver();
        $result = [];

        foreach ($storage->files($dir) as $path) {
            $filename = basename($path);

            if (str_starts_with($filename, 'thumb_')) {
                continue;
            }

            $mime = $storage->mimeType($path) ?? '';
            $isImg = str_starts_with($mime, 'image/');
            if ($imagesOnly !== $isImg) continue;

            $url = $urlResolver->tempUrl($path);

            if ($isImg) {
                $thumbName = $this->findThumbName($path, $storage);

                $result[] = [
                    'url'       => $url,
                    'thumbnail' => $thumbName
                        ? $urlResolver->tempUrl(pathinfo($path, PATHINFO_DIRNAME) . '/' . $thumbName)
                        : null,
                    'source'    => $source,
                    'title'     => null,
                ];
            } else {
                $result[] = [
                    'url'    => $url,
                    'source' => $source,
                    'title'  => null,
                ];
            }
        }

        return $result;
    }

    public function tempImages(): array
    {
        return $this->scanDir($this->tempDir(), true, 'temp');
    }

    public function tempFiles(): array
    {
        return $this->scanDir($this->tempDir(), false, 'temp');
    }

    public function storedImages(): array
    {
        if (!$this->getKey()) {
            return [];
        }

        $urlResolver = $this->urlResolver();
        $storeDir = $this->storeDir();

        return collect($this->imageList())
            ->filter(fn($img) => !empty($img['original']))
            ->map(function ($img) use ($urlResolver, $storeDir) {
                $original  = $img['original'];
                $thumbnail = $img['thumbnail'] ?? null;

                return [
                    'url'       => $urlResolver->storeUrl("{$storeDir}/{$original}"),
                    'thumbnail' => $thumbnail
                        ? $urlResolver->storeUrl("{$storeDir}/{$thumbnail}")
                        : null,
                    'source'    => 'stored',
                    'title'     => $img['title'] ?? null,
                ];
            })
            ->values()
            ->toArray();
    }

    public function storedPlainFiles(): array
    {
        if (!$this->getKey()) {
            return [];
        }

        $urlResolver = $this->urlResolver();
        $storeDir = $this->storeDir();

        return collect($this->fileList())
            ->filter(fn($f) => !empty($f['name']))
            ->map(function ($f) use ($urlResolver, $storeDir) {
                return [
                    'url'    => $urlResolver->storeUrl("{$storeDir}/{$f['name']}"),
                    'source' => 'stored',
                    'title'  => $f['title'] ?? null,
                ];
            })
            ->values()
            ->toArray();
    }

    /* ============================================================
     *  ПЕРЕНОС TEMP → STORE
     * ============================================================ */

    public function moveTempToStore(): void
    {
        // Гарантируем загрузку owner, чтобы избежать N+1 при tempDir()
        $this->loadMissing('owner');

        $tempStorage  = $this->tempStorage();
        $storeStorage = $this->storeStorage();

        $storeDir = $this->storeDir();
        $storeStorage->makeDirectory($storeDir);

        $images = $this->actuateImageList($this->imageList());
        $files  = $this->actuateFileList($this->fileList());

        $maxImages = $this->maxImages();
        $maxFiles  = $this->maxFiles();

        $userId = $this->owner?->user_id
            ?? auth()->id()
            ?? 0;

        $modelName = $this->getTable();

        $tempDirs = array_unique([
            $this->tempDir(),
            "temp/users/{$userId}/models/{$modelName}/0",
        ]);

        $filesToMove = [];
        $tempImagesCount = 0;
        $tempFilesCount = 0;

        // 1. Сканируем ОДИН раз, запоминаем MIME и тип
        foreach ($tempDirs as $tempDir) {
            if (!$tempStorage->exists($tempDir)) {
                continue;
            }

            foreach ($tempStorage->files($tempDir) as $path) {
                $filename = basename($path);
                $isThumb  = str_starts_with($filename, 'thumb_');
                $mime     = $tempStorage->mimeType($path) ?? '';
                $isImage  = str_starts_with($mime, 'image/');

                if (!$isThumb) {
                    if ($isImage) $tempImagesCount++;
                    else $tempFilesCount++;
                }

                $filesToMove[] = [
                    'path'     => $path,
                    'filename' => $filename,
                    'isThumb'  => $isThumb,
                    'isImage'  => $isImage,
                    'mime'     => $mime,
                ];
            }
        }

        // 2. Проверка лимитов
        $totalImages = count($images) + $tempImagesCount;
        if ($totalImages > $maxImages) {
            throw new FileLimitExceededException('images', $maxImages, $totalImages);
        }

        $totalFiles = count($files) + $tempFilesCount;
        if ($totalFiles > $maxFiles) {
            throw new FileLimitExceededException('files', $maxFiles, $totalFiles);
        }

        $movedImages = [];
        $movedFiles  = [];

        // 3. Переносим файлы (используя закэшированные данные)
        foreach ($filesToMove as $item) {
            $this->transferFile(
                $tempStorage,
                $item['path'],
                $storeStorage,
                "{$storeDir}/{$item['filename']}",
                $item['mime'] // Передаем MIME (сработает для S3, проигнорируется локально)
            );

            if ($item['isThumb']) {
                continue;
            }

            if ($item['isImage']) {
                $movedImages[] = $item['filename'];
            } else {
                $movedFiles[] = $item['filename'];
            }
        }

        // Чистим пустые папки temp
        foreach ($tempDirs as $tempDir) {
            if ($tempStorage->exists($tempDir)) {
                $tempStorage->deleteDirectory($tempDir);
            }
        }

        // 4. Обновляем реестр в БД
        if ($movedImages || $movedFiles) {
            // Собираем имена перенесенных тумбнейлов (чтобы не делать exists() в S3)
            $movedThumbs = collect($filesToMove)
                ->where('isThumb', true)
                ->pluck('filename')
                ->flip()
                ->toArray();

            foreach ($movedImages as $name) {
                $thumbName = "thumb_{$name}";
                $images[] = [
                    'original'  => $name,
                    // Проверяем наличие тумбнейла в памяти, а не через запрос к S3
                    'thumbnail' => isset($movedThumbs[$thumbName]) ? $thumbName : null,
                    'title'     => null,
                ];
            }

            foreach ($movedFiles as $name) {
                $files[] = [
                    'name'  => $name,
                    'title' => null,
                ];
            }

            $this->images = $images ?: null;
            $this->files  = $files ?: null;
            $this->saveQuietly();
        }

        // 5. Генерация тумбнейлов, если их нет
        if ($movedImages) {
            $needProcessing = [];
            foreach ($movedImages as $name) {
                $thumbName = "thumb_{$name}";

                // Если мы не перенесли тумбнейл — его нужно сгенерировать
                // (или если мы используем локальный диск, можно проверить exists, но проще проверить флаг)
                $thumbMoved = collect($filesToMove)
                    ->where('filename', $thumbName)
                    ->isNotEmpty();

                if (!$thumbMoved) {
                    $needProcessing[] = $name;
                }
            }

            if (!empty($needProcessing)) {
                $this->runProcessors($needProcessing);
            }
        }
    }

    /* ============================================================
     *  СИНХРОНИЗАЦИЯ С ФРОНТА И ОСТАЛЬНОЕ
     * ============================================================ */

    public function syncFilesFromRequest(?array $images, ?array $files): void
    {
        $this->moveTempToStore();

        $maxImages = $this->maxImages();
        $maxFiles  = $this->maxFiles();

        if ($images !== null && count($images) > $maxImages) {
            throw new FileLimitExceededException('images', $maxImages, count($images));
        }

        if ($files !== null && count($files) > $maxFiles) {
            throw new FileLimitExceededException('files', $maxFiles, count($files));
        }

        if ($images !== null) {
            $incoming = $this->extractNamesFromUrls($images);
            $existing = $this->imageList();

            if (!empty($existing)) {
                foreach ($existing as $img) {
                    $original = $img['original'] ?? null;
                    if ($original && !isset($incoming[$original])) {
                        $this->deleteStoredImageByName($original);
                    }
                }
            }
        }

        if ($files !== null) {
            $incoming = $this->extractNamesFromUrls($files);
            $existing = $this->fileList();

            if (!empty($existing)) {
                foreach ($existing as $f) {
                    $name = $f['name'] ?? null;
                    if ($name && !isset($incoming[$name])) {
                        $this->deleteStoredFileByName($name);
                    }
                }
            }
        }

        $this->applyTitlesFromRequest($images, $files);

        if ($images !== null) {
            $this->reorderImagesByPayload($images);
        }
        if ($files !== null) {
            $this->reorderFilesByPayload($files);
        }
    }

    protected function extractNamesFromUrls(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            $url = $item['url'] ?? null;
            if (!$url) continue;
            $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
            if ($name) $names[$name] = true;
        }
        return $names;
    }

    protected function applyTitlesFromRequest(?array $images, ?array $files): void
    {
        $urlResolver = $this->urlResolver();
        $storeDir = $this->storeDir();

        if ($images !== null) {
            $titlesByUrl = $this->extractTitlesByUrl($images);
            $current = $this->imageList();
            $changed = false;

            foreach ($current as &$img) {
                $original = $img['original'] ?? null;
                if (!$original) continue;

                $url = $urlResolver->storeUrl("{$storeDir}/{$original}");
                if (array_key_exists($url, $titlesByUrl)) {
                    $img['title'] = $titlesByUrl[$url];
                    $changed = true;
                }
            }
            unset($img);

            if ($changed) {
                $this->images = $current;
                $this->saveQuietly();
            }
        }

        if ($files !== null) {
            $titlesByUrl = $this->extractTitlesByUrl($files);
            $current = $this->fileList();
            $changed = false;

            foreach ($current as &$f) {
                $name = $f['name'] ?? null;
                if (!$name) continue;

                $url = $urlResolver->storeUrl("{$storeDir}/{$name}");
                if (array_key_exists($url, $titlesByUrl)) {
                    $f['title'] = $titlesByUrl[$url];
                    $changed = true;
                }
            }
            unset($f);

            if ($changed) {
                $this->files = $current;
                $this->saveQuietly();
            }
        }
    }

    protected function extractTitlesByUrl(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $url = $item['url'] ?? null;
            if (!$url) continue;
            $result[$url] = $item['title'] ?? null;
        }
        return $result;
    }

    protected function reorderImagesByPayload(array $incoming): void
    {
        $current = collect($this->imageList())->keyBy('original');

        if ($current->isEmpty()) return;

        $ordered = [];
        foreach ($incoming as $item) {
            $url = $item['url'] ?? null;
            if (!$url) continue;
            $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
            if ($name && $current->has($name)) {
                $ordered[] = $current->get($name);
            }
        }

        $this->images = $ordered ?: null;
        $this->saveQuietly();
    }

    protected function reorderFilesByPayload(array $incoming): void
    {
        $current = collect($this->fileList())->keyBy('name');

        if ($current->isEmpty()) return;

        $ordered = [];
        foreach ($incoming as $item) {
            $url = $item['url'] ?? null;
            if (!$url) continue;
            $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
            if ($name && $current->has($name)) {
                $ordered[] = $current->get($name);
            }
        }

        $this->files = $ordered ?: null;
        $this->saveQuietly();
    }

    public function deleteAllFiles(): void
    {
        if ($this->getKey()) {
            $this->storeStorage()->deleteDirectory($this->storeDir());
        }

        $this->tempStorage()->deleteDirectory($this->tempDir());
    }

    public function deleteStoredImage(string $url): void
    {
        $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if ($name) {
            $this->deleteStoredImageByName($name);
        }
    }

    public function deleteStoredImageByName(string $original): void
    {
        $storage = $this->storeStorage();
        $images = collect($this->imageList());
        $entry = $images->firstWhere('original', $original);

        if (!$entry) return;

        $storage->delete("{$this->storeDir()}/{$entry['original']}");
        if (!empty($entry['thumbnail'])) {
            $storage->delete("{$this->storeDir()}/{$entry['thumbnail']}");
        }

        $this->images = $images->reject(fn($i) => $i['original'] === $original)->values()->toArray() ?: null;
        $this->saveQuietly();
    }

    public function deleteStoredFile(string $url): void
    {
        $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if ($name) {
            $this->deleteStoredFileByName($name);
        }
    }

    public function deleteStoredFileByName(string $name): void
    {
        $storage = $this->storeStorage();
        $storage->delete("{$this->storeDir()}/{$name}");

        $this->files = collect($this->fileList())->reject(fn($f) => $f['name'] === $name)->values()->toArray() ?: null;
        $this->saveQuietly();
    }

    protected function runProcessors(array $filenames): void
    {
        if (!property_exists($this, 'fileProcessors')) return;

        $processors = $this->fileProcessors;
        if (empty($processors) || empty($filenames)) return;

        foreach ($processors as $processorClass) {
            app($processorClass)->handle($this, $filenames);
        }
    }

    protected function isImagePath(string $path, ?FileStorage $storage = null): bool
    {
        $storage ??= $this->storeStorage();
        $mime = $storage->mimeType($path) ?? '';
        return str_starts_with($mime, 'image/');
    }

    protected function findThumbName(string $path, ?FileStorage $storage = null): ?string
    {
        $storage ??= $this->storeStorage();
        $dir  = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $ext  = pathinfo($path, PATHINFO_EXTENSION);

        $thumbName = "thumb_{$name}.{$ext}";

        return $storage->exists("{$dir}/{$thumbName}") ? $thumbName : null;
    }

    public function shardPrefix(): string
    {
        $id = (string) ($this->getKey() ?? 0);
        $hash = md5($id);

        $first  = substr($hash, 0, 2);
        $second = substr($hash, 2, 2);

        return "{$first}/{$second}";
    }

    public function maxImages(): int
    {
        return (int) config('file-sets.max_images', 100);
    }

    public function maxFiles(): int
    {
        return (int) config('file-sets.max_files', 20);
    }
}
