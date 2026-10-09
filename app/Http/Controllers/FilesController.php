<?php
// app/Http/Controllers/FilesController.php

namespace App\Http\Controllers;

use App\Enums\FilePurpose;
use App\Files\ThumbnailMaker;
use App\Models\FileSet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FilesController extends Controller
{
    // ============================================================
    // PRELOAD
    // ============================================================

    public function preload(
        Request $request,
        ThumbnailMaker $thumbs,
        string $modelName,
        int $modelId,
    ) {
        $rules = $this->rulesFor($modelName);

        $maxImages = (int) config('files.max_images', 100);
        $maxFiles  = (int) config('files.max_files', 20);

        $request->validate([
            'files'   => 'required|array|max:' . ($maxImages + $maxFiles),
            'files.*' => 'file|max:' . $rules['max_size_kb'],
            'purpose' => ['nullable', 'string', Rule::in(FilePurpose::values())],
        ]);

        $purpose = $request->query('purpose');
        $fileSet = null;

        if ($purpose !== null) {
            // === Ветка FileSet ===
            $class = $this->resolveModelClass($modelName);

            $fileSet = FileSet::firstOrCreate([
                'model'    => $class,
                'model_id' => $modelId,
                'purpose'  => $purpose,
            ]);

            $dir = $fileSet->tempDir();
        } else {
            // === Старое поведение ===
            $userId = auth()->id() ?? 0;
            $dir = "temp/users/{$userId}/models/{$modelName}/{$modelId}";
        }

        Storage::disk('public')->makeDirectory($dir);

        foreach ($request->file('files') as $file) {
            $mime = $file->getMimeType();

            if (!in_array($mime, array_merge($rules['image_mimes'], $rules['file_mimes']), true)) {
                throw ValidationException::withMessages([
                    'files' => "Тип файла {$mime} не разрешён.",
                ]);
            }

            $filename = $file->hashName();
            $file->storeAs($dir, $filename, 'public');

            $isImage = str_starts_with($mime, 'image/');

            if ($isImage) {
                $thumbs->make("{$dir}/{$filename}");
            }
        }

        // Если FileSet — сразу переносим temp → store
        if ($fileSet) {
            $fileSet->moveTempToStore();

            return response()->json([
                'uploaded' => array_merge(
                    $fileSet->storedImages(),
                    $fileSet->storedPlainFiles(),
                ),
            ]);
        }

        // === Старое поведение: возвращаем temp-файлы ===
        $uploaded = [];

        foreach (Storage::disk('public')->files($dir) as $path) {
            $filename = basename($path);
            if (str_starts_with($filename, 'thumb_')) continue;

            $isImg = $this->isImagePath($path);
            $url = Storage::disk('public')->url($path);

            if ($isImg) {
                $thumbName = $this->findThumbName($path);
                $uploaded[] = [
                    'url'       => $url,
                    'thumbnail' => $thumbName
                        ? Storage::disk('public')->url(pathinfo($path, PATHINFO_DIRNAME) . '/' . $thumbName)
                        : null,
                    'source'    => 'temp',
                    'title'     => null,
                ];
            } else {
                $uploaded[] = [
                    'url'    => $url,
                    'source' => 'temp',
                    'title'  => null,
                ];
            }
        }

        return response()->json(['uploaded' => $uploaded]);
    }

    // ============================================================
    // LIST
    // ============================================================

    public function list(
        Request $request,
        string $modelName,
        int $modelId,
    ) {
        $request->validate([
            'purpose' => ['nullable', 'string', Rule::in(FilePurpose::values())],
        ]);

        $purpose = $request->query('purpose');

        // === Ветка FileSet ===
        if ($purpose !== null) {
            $class = $this->resolveModelClass($modelName);
            $model = $class::find($modelId);

            $fileSet = $model?->findFileSet($purpose);

            if (!$fileSet) {
                // Пустышка — только для чтения temp
                $fileSet = new FileSet([
                    'model'    => $class,
                    'model_id' => $modelId,
                    'purpose'  => $purpose,
                ]);
            }

            $tempImages = $fileSet->tempImages();
            $tempFiles  = $fileSet->tempFiles();

            // Если FileSet не сохранён — stored пустой
            $storedImages = $fileSet->exists ? $fileSet->storedImages() : [];
            $storedFiles  = $fileSet->exists ? $fileSet->storedPlainFiles() : [];

            return response()->json([
                'images' => array_merge($tempImages, $storedImages),
                'files'  => array_merge($tempFiles, $storedFiles),

                'temp' => [
                    'images' => $tempImages,
                    'files'  => $tempFiles,
                ],
                'stored' => [
                    'images' => $storedImages,
                    'files'  => $storedFiles,
                ],
            ]);
        }

        // === Старое поведение ===
        $class = $this->resolveModelClass($modelName);
        $model = $class::find($modelId);

        $userId = auth()->id() ?? 0;
        $tempDir = "temp/users/{$userId}/models/{$modelName}/{$modelId}";

        $tempImages = $this->filesIn($tempDir, true);
        $tempFiles  = $this->filesIn($tempDir, false);

        $storedImages = $model ? $model->storedImages() : [];
        $storedFiles  = $model ? $model->storedPlainFiles() : [];

        return response()->json([
            'images' => array_merge($tempImages, $storedImages),
            'files'  => array_merge($tempFiles, $storedFiles),

            'temp' => [
                'images' => $tempImages,
                'files'  => $tempFiles,
            ],
            'stored' => [
                'images' => $storedImages,
                'files'  => $storedFiles,
            ],
        ]);
    }

    // ============================================================
    // DELETE TEMP
    // ============================================================

    public function deleteTemp(
        Request $request,
        string $modelName,
        int $modelId,
    ) {
        $request->validate([
            'url'     => 'required|string',
            'purpose' => ['nullable', 'string', Rule::in(FilePurpose::values())],
        ]);

        $purpose = $request->query('purpose');

        // === Ветка FileSet ===
        if ($purpose !== null) {
            $class = $this->resolveModelClass($modelName);
            $model = $class::find($modelId);

            $fileSet = $model?->findFileSet($purpose);

            $url  = $request->input('url');
            $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);

            if (!$name) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Не удалось определить имя файла',
                ], 422);
            }

            // Файл может быть в temp или в store
            if ($fileSet) {
                // Проверим, что файл в реестре FileSet
                $inRegistry = collect($fileSet->imageList())
                    ->pluck('original')
                    ->contains($name);

                if ($inRegistry) {
                    $fileSet->deleteStoredImage($url);
                    return response()->json(['ok' => true]);
                }

                $inFiles = collect($fileSet->fileList())
                    ->pluck('name')
                    ->contains($name);

                if ($inFiles) {
                    $fileSet->deleteStoredFile($url);
                    return response()->json(['ok' => true]);
                }
            }

            // Файл в temp (не попал в store)
            $dir = $fileSet
                ? $fileSet->tempDir()
                : "temp/users/" . (auth()->id() ?? 0) . "/models/{$modelName}/{$modelId}";

            $disk = Storage::disk('public');
            $path = "{$dir}/{$name}";

            if (!$disk->exists($path)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Файл не найден',
                ], 404);
            }

            $disk->delete($path);

            $thumbPath = "{$dir}/thumb_{$name}";
            if ($disk->exists($thumbPath)) {
                $disk->delete($thumbPath);
            }

            return response()->json(['ok' => true]);
        }

        // === Старое поведение ===
        $userId = auth()->id() ?? 0;
        $dir = "temp/users/{$userId}/models/{$modelName}/{$modelId}";

        $url  = $request->input('url');
        $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);

        if (!$name) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось определить имя файла',
            ], 422);
        }

        $disk = Storage::disk('public');
        $path = "{$dir}/{$name}";

        if (!$disk->exists($path)) {
            return response()->json([
                'ok' => false,
                'message' => 'Файл не найден',
            ], 404);
        }

        $disk->delete($path);

        $thumbPath = "{$dir}/thumb_{$name}";
        if ($disk->exists($thumbPath)) {
            $disk->delete($thumbPath);
        }

        return response()->json(['ok' => true]);
    }

    // ============================================================
    // Хелперы
    // ============================================================

    /**
     * Старое сканирование папки.
     */
    private function filesIn(string $dir, bool $imagesOnly): array
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

    private function findThumbName(string $path): ?string
    {
        $disk = Storage::disk('public');
        $dir  = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $ext  = pathinfo($path, PATHINFO_EXTENSION);

        $thumbName = "thumb_{$name}.{$ext}";

        return $disk->exists("{$dir}/{$thumbName}") ? $thumbName : null;
    }

    private function isImagePath(string $path): bool
    {
        $mime = Storage::disk('public')->mimeType($path) ?? '';
        return str_starts_with($mime, 'image/');
    }

    private function rulesFor(string $modelName): array
    {
        $class = $this->resolveModelClass($modelName);
        return $class::$fileRules ?? [
                'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                'file_mimes'  => ['application/pdf'],
                'max_images'  => 10,
                'max_files'   => 10,
                'max_size_kb' => 10240,
            ];
    }

    private function resolveModelClass(string $modelName): string
    {
        $studly = str($modelName)->studly()->singular()->toString();
        return "App\\Models\\{$studly}";
    }
}
