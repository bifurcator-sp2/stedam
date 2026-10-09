<?php
// app/Traits/FilesContainer.php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait FilesContainer
{
    /* ============================================================
     *  BOOT
     * ============================================================ */

    protected static function bootFilesContainer(): void
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
     *  ПУТИ
     * ============================================================ */

    public function tempDir(): string
    {
        $userId = auth()->id() ?? 0;
        $modelName = $this->getTable();
        $modelId = $this->getKey() ?: 0;

        return "temp/users/{$userId}/models/{$modelName}/{$modelId}";
    }

    public function storeDir(): string
    {
        if (!$this->getKey()) {
            // Новая модель — папка ещё не нужна, но путь должен быть валидным
            return "models/{$this->getTable()}/_new";
        }

        return "models/{$this->getTable()}/{$this->shardPrefix()}/{$this->getKey()}";
    }

    /* ============================================================
     *  СПИСКИ ИЗ БД
     * ============================================================ */

    public function imageList(): array
    {
        return is_array($this->images) ? $this->images : [];
    }

    public function fileList(): array
    {
        return is_array($this->files) ? $this->files : [];
    }

    /* ============================================================
     *  АКТУАЛИЗАЦИЯ СПИСКОВ
     * ============================================================ */

    public function actuateImageList(array $images): array
    {
        if (!$this->getKey() || empty($images)) {
            return $images;
        }

        $disk = Storage::disk('public');
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
            if (!$disk->exists($originalPath)) {
                $changed = true;
                continue;
            }

            if (!empty($img['thumbnail'])) {
                $thumbPath = "{$storeDir}/{$img['thumbnail']}";
                if (!$disk->exists($thumbPath)) {
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

        $disk = Storage::disk('public');
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
            if (!$disk->exists($path)) {
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
     *  ПРУНИНГ СИРОТ
     * ============================================================ */

    public function pruneOrphanImages(): array
    {
        if (!$this->getKey()) {
            return ['deleted' => [], 'kept' => []];
        }

        $disk = Storage::disk('public');
        $storeDir = $this->storeDir();

        if (!$disk->exists($storeDir)) {
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

        foreach ($disk->files($storeDir) as $path) {
            $filename = basename($path);

            if ($filename === '.gitignore' || str_starts_with($filename, '.')) {
                continue;
            }

            $isThumb = str_starts_with($filename, 'thumb_');

            if ($isThumb) {
                if (isset($expectedThumbs[$filename])) {
                    $kept[] = $filename;
                    continue;
                }
                $disk->delete($path);
                $deleted[] = $filename;
                continue;
            }

            if (isset($expectedOriginals[$filename])) {
                $kept[] = $filename;
                continue;
            }

            $disk->delete($path);
            $deleted[] = $filename;
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    public function pruneOrphanFiles(): array
    {
        if (!$this->getKey()) {
            return ['deleted' => [], 'kept' => []];
        }

        $disk = Storage::disk('public');
        $storeDir = $this->storeDir();

        if (!$disk->exists($storeDir)) {
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

        foreach ($disk->files($storeDir) as $path) {
            $filename = basename($path);

            if ($filename === '.gitignore' || str_starts_with($filename, '.')) {
                continue;
            }

            if (str_starts_with($filename, 'thumb_')) {
                continue;
            }

            if ($this->isImagePath($path)) {
                continue;
            }

            if (isset($expected[$filename])) {
                $kept[] = $filename;
                continue;
            }

            $disk->delete($path);
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

    /* ============================================================
     *  СКАНИРОВАНИЕ ПАПОК (temp)
     * ============================================================ */

    public function scanDir(string $dir, bool $imagesOnly, string $source): array
    {
        $disk = Storage::disk('public');

        if (!$disk->exists($dir)) {
            return [];
        }

        $result = [];

        foreach ($disk->files($dir) as $path) {
            $filename = basename($path);

            if (str_starts_with($filename, 'thumb_')) {
                continue;
            }

            $mime = $disk->mimeType($path) ?? '';
            $isImg = str_starts_with($mime, 'image/');
            if ($imagesOnly !== $isImg) continue;

            $url = $disk->url($path);

            if ($isImg) {
                $thumbName = $this->findThumbName($path);

                $result[] = [
                    'url'       => $url,
                    'thumbnail' => $thumbName
                        ? $disk->url(pathinfo($path, PATHINFO_DIRNAME) . '/' . $thumbName)
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

    /* ============================================================
     *  СПИСКИ TEMP
     * ============================================================ */

    public function tempImages(): array
    {
        return $this->scanDir($this->tempDir(), true, 'temp');
    }

    public function tempFiles(): array
    {
        return $this->scanDir($this->tempDir(), false, 'temp');
    }

    /* ============================================================
     *  СПИСКИ STORE (только из реестра, без сканирования диска)
     * ============================================================ */

    public function storedImages(): array
    {
        if (!$this->getKey()) {
            return [];
        }

        $disk = Storage::disk('public');
        $storeDir = $this->storeDir();

        return collect($this->imageList())
            ->filter(fn($img) => !empty($img['original']))
            ->map(function ($img) use ($disk, $storeDir) {
                $original  = $img['original'];
                $thumbnail = $img['thumbnail'] ?? null;

                return [
                    'url'       => $disk->url("{$storeDir}/{$original}"),
                    'thumbnail' => $thumbnail
                        ? $disk->url("{$storeDir}/{$thumbnail}")
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

        $disk = Storage::disk('public');
        $storeDir = $this->storeDir();

        return collect($this->fileList())
            ->filter(fn($f) => !empty($f['name']))
            ->map(function ($f) use ($disk, $storeDir) {
                return [
                    'url'    => $disk->url("{$storeDir}/{$f['name']}"),
                    'source' => 'stored',
                    'title'  => $f['title'] ?? null,
                ];
            })
            ->values()
            ->toArray();
    }

    /* ============================================================
     *  ДОБАВЛЕНИЕ В TEMP
     * ============================================================ */

    public function addTempImage(UploadedFile $file): string
    {
        $filename = $file->hashName();
        $file->storeAs($this->tempDir(), $filename, 'public');

        return $filename;
    }

    public function addTempFile(UploadedFile $file): string
    {
        $filename = $file->hashName();
        $file->storeAs($this->tempDir(), $filename, 'public');

        return $filename;
    }

    public function deleteTempFile(string $filename): void
    {
        $dir = $this->tempDir();
        $disk = Storage::disk('public');

        $disk->delete("{$dir}/{$filename}");

        $thumbPath = "{$dir}/thumb_{$filename}";
        if ($disk->exists($thumbPath)) {
            $disk->delete($thumbPath);
        }
    }

    /* ============================================================
     *  ПЕРЕНОС TEMP → STORE
     * ============================================================ */

    public function moveTempToStore(): void
    {
        $storeDir = $this->storeDir();
        $disk = Storage::disk('public');
        $disk->makeDirectory($storeDir);

        $images = $this->actuateImageList($this->imageList());
        $files  = $this->actuateFileList($this->fileList());

        $maxImages = $this->maxImages();
        $maxFiles  = $this->maxFiles();

        $userId = auth()->id() ?? 0;
        $modelName = $this->getTable();

        $tempDirs = array_unique([
            $this->tempDir(),
            "temp/users/{$userId}/models/{$modelName}/0",
        ]);

        // Считаем оригиналы в temp (thumb не считаем)
        $tempImages = [];
        $tempFiles  = [];

        foreach ($tempDirs as $tempDir) {
            if (!$disk->exists($tempDir)) continue;

            foreach ($disk->files($tempDir) as $path) {
                $filename = basename($path);

                if (str_starts_with($filename, 'thumb_')) {
                    continue;
                }

                if ($this->isImagePath($path)) {
                    $tempImages[] = $path;
                } else {
                    $tempFiles[] = $path;
                }
            }
        }

        // Проверка ДО переноса
        $totalImages = count($images) + count($tempImages);
        if ($totalImages > $maxImages) {
            throw new \App\Exceptions\FileLimitExceededException(
                'images',
                $maxImages,
                $totalImages,
        );
        }

        $totalFiles = count($files) + count($tempFiles);
        if ($totalFiles > $maxFiles) {
            throw new \App\Exceptions\FileLimitExceededException(
                'files',
                $maxFiles,
                $totalFiles,
        );
        }

        // Лимиты ок — переносим всё
        $movedImages = [];
        $movedFiles  = [];

        foreach ($tempDirs as $tempDir) {
            if (!$disk->exists($tempDir)) continue;

            foreach ($disk->files($tempDir) as $path) {
                $filename = basename($path);

                if (str_starts_with($filename, 'thumb_')) {
                    $disk->move($path, "{$storeDir}/{$filename}");
                    continue;
                }

                $disk->move($path, "{$storeDir}/{$filename}");

                if ($this->isImagePath("{$storeDir}/{$filename}")) {
                    $movedImages[] = $filename;
                } else {
                    $movedFiles[] = $filename;
                }
            }

            $disk->deleteDirectory($tempDir);
        }

        if ($movedImages || $movedFiles) {
            foreach ($movedImages as $name) {
                $images[] = [
                    'original'  => $name,
                    'thumbnail' => $disk->exists("{$storeDir}/thumb_{$name}")
                        ? "thumb_{$name}"
                        : null,
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

        // Fallback-обработка тумбов
        if ($movedImages) {
            $needProcessing = [];
            foreach ($movedImages as $name) {
                if (!$disk->exists("{$storeDir}/thumb_{$name}")) {
                    $needProcessing[] = $name;
                }
            }
            if (!empty($needProcessing)) {
                $this->runProcessors($needProcessing);
            }
        }

        if ($movedImages || $movedFiles) {
            $this->pruneOrphans();
        }
    }

    /* ============================================================
     *  СИНХРОНИЗАЦИЯ ПО ЗАПРОСУ С ФРОНТА
     * ============================================================ */

    /**
     * Синхронизирует файлы модели с тем, что пришло с фронта.
     *
     * Порядок:
     *  1. moveTempToStore — переносит temp → store, актуализирует реестр, прунит сирот.
     *  2. Удаляем из реестра то, чего нет в payload.
     *  3. Применяем title из payload.
     *  4. Переупорядочиваем реестр по порядку из payload.
     */
    public function syncFilesFromRequest(?array $images, ?array $files): void
    {
        // 1. Перенос temp → store (внутри проверяет лимиты)
        $this->moveTempToStore();

        $maxImages = $this->maxImages();
        $maxFiles  = $this->maxFiles();

        // 2. Проверка payload на лимиты
        if ($images !== null && count($images) > $maxImages) {
            throw new \App\Exceptions\FileLimitExceededException(
                'images',
                $maxImages,
                count($images),
        );
        }

        if ($files !== null && count($files) > $maxFiles) {
            throw new \App\Exceptions\FileLimitExceededException(
                'files',
                $maxFiles,
                count($files),
        );
        }

        // 3. Удаляем из реестра то, чего нет в payload
        if ($images !== null) {
            $incoming = $this->extractNamesFromUrls($images);

            foreach ($this->imageList() as $img) {
                $original = $img['original'] ?? null;
                if ($original && !isset($incoming[$original])) {
                    $this->deleteStoredImageByName($original);
                }
            }
        }

        if ($files !== null) {
            $incoming = $this->extractNamesFromUrls($files);

            foreach ($this->fileList() as $f) {
                $name = $f['name'] ?? null;
                if ($name && !isset($incoming[$name])) {
                    $this->deleteStoredFileByName($name);
                }
            }
        }

        // 4. Применяем title
        $this->applyTitlesFromRequest($images, $files);

        // 5. Переупорядочиваем
        if ($images !== null) {
            $this->reorderImagesByPayload($images);
        }
        if ($files !== null) {
            $this->reorderFilesByPayload($files);
        }
    }

    /**
     * Собирает множество basename'ов из массива URL'ов.
     *
     * @param array $items [['url' => '...', ...], ...]
     * @return array<string, true>
     */
    protected function extractNamesFromUrls(array $items): array
    {
        $names = [];

        foreach ($items as $item) {
            $url = $item['url'] ?? null;
            if (!$url) continue;

            $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
            if ($name) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * Применяет title из пришедших данных к реестру.
     */
    protected function applyTitlesFromRequest(?array $images, ?array $files): void
    {
        $disk = Storage::disk('public');
        $storeDir = $this->storeDir();

        if ($images !== null) {
            $titlesByUrl = $this->extractTitlesByUrl($images);
            $current = $this->imageList();
            $changed = false;

            foreach ($current as &$img) {
                $original = $img['original'] ?? null;
                if (!$original) continue;

                $url = $disk->url("{$storeDir}/{$original}");
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

                $url = $disk->url("{$storeDir}/{$name}");
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

    /**
     * @return array<string, string|null> [url => title]
     */
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

    /**
     * Переупорядочивает $this->images по порядку из payload.
     * Элементы, которых нет в payload, уже удалены на шаге 2.
     */
    protected function reorderImagesByPayload(array $incoming): void
    {
        $current = collect($this->imageList())->keyBy('original');

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

    /**
     * Переупорядочивает $this->files по порядку из payload.
     */
    protected function reorderFilesByPayload(array $incoming): void
    {
        $current = collect($this->fileList())->keyBy('name');

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

    /* ============================================================
     *  УДАЛЕНИЕ
     * ============================================================ */

    public function deleteAllFiles(): void
    {
        $disk = Storage::disk('public');

        if ($this->getKey()) {
            $disk->deleteDirectory($this->storeDir());
        }
        $disk->deleteDirectory($this->tempDir());
    }

    /**
     * Удаляет изображение по URL.
     */
    public function deleteStoredImage(string $url): void
    {
        $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if ($name) {
            $this->deleteStoredImageByName($name);
        }
    }

    /**
     * Удаляет изображение по имени оригинала.
     */
    public function deleteStoredImageByName(string $original): void
    {
        $disk = Storage::disk('public');
        $images = collect($this->imageList());
        $entry = $images->firstWhere('original', $original);

        if (!$entry) {
            return;
        }

        $disk->delete("{$this->storeDir()}/{$entry['original']}");
        if (!empty($entry['thumbnail'])) {
            $disk->delete("{$this->storeDir()}/{$entry['thumbnail']}");
        }

        $this->images = $images
            ->reject(fn($i) => $i['original'] === $original)
            ->values()
            ->toArray() ?: null;

        $this->saveQuietly();
    }

    /**
     * Удаляет файл по URL.
     */
    public function deleteStoredFile(string $url): void
    {
        $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if ($name) {
            $this->deleteStoredFileByName($name);
        }
    }

    /**
     * Удаляет файл по имени.
     */
    public function deleteStoredFileByName(string $name): void
    {
        $disk = Storage::disk('public');
        $disk->delete("{$this->storeDir()}/{$name}");

        $this->files = collect($this->fileList())
            ->reject(fn($f) => $f['name'] === $name)
            ->values()
            ->toArray() ?: null;

        $this->saveQuietly();
    }

    /* ============================================================
     *  ПРОЦЕССОРЫ
     * ============================================================ */

    protected function runProcessors(array $filenames): void
    {
        if (!property_exists($this, 'fileProcessors')) {
            return;
        }

        $processors = $this->fileProcessors;
        if (empty($processors) || empty($filenames)) {
            return;
        }

        foreach ($processors as $processorClass) {
            app($processorClass)->handle($this, $filenames);
        }
    }

    /* ============================================================
     *  УТИЛИТЫ
     * ============================================================ */

    protected function isImagePath(string $path): bool
    {
        $mime = Storage::disk('public')->mimeType($path) ?? '';
        return str_starts_with($mime, 'image/');
    }

    protected function findThumbName(string $path): ?string
    {
        $disk = Storage::disk('public');
        $dir  = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $ext  = pathinfo($path, PATHINFO_EXTENSION);

        $thumbName = "thumb_{$name}.{$ext}";

        return $disk->exists("{$dir}/{$thumbName}") ? $thumbName : null;
    }
    /**
     * Шардированный префикс для модели.
     * Пример: для id=123456 вернёт "e1/0a".
     */
    protected function shardPrefix(): string
    {
        $id = (string) ($this->getKey() ?? 0);
        $hash = md5($id);

        $first  = substr($hash, 0, 2);
        $second = substr($hash, 2, 2);

        return "{$first}/{$second}";
    }

    public function maxImages(): int
    {
        return (int) config('files.max_images', 100);
    }

    public function maxFiles(): int
    {
        return (int) config('files.max_files', 20);
    }


    /* ============================================================
     *  СИНХРОНИЗАЦИЯ (задел на будущее)
     * ============================================================ */

    public function synchronizeFiles(): void
    {
        $this->moveTempToStore();
        $this->pruneOrphans();
    }
}
