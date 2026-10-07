<?php
// app/Http/Controllers/FilesController.php

namespace App\Http\Controllers;

use App\Files\ThumbnailMaker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FilesController extends Controller
{
    public function preload(
        Request $request,
        ThumbnailMaker $thumbs,
        string $modelName,
        int $modelId,
    ) {
        $rules = $this->rulesFor($modelName);

        $request->validate([
            'files'   => 'required|array|max:' . ($rules['max_images'] + $rules['max_files']),
            'files.*' => 'file|max:' . $rules['max_size_kb'],
        ]);

        $userId = auth()->id() ?? 0;
        $dir = "temp/users/{$userId}/models/{$modelName}/{$modelId}";

        Storage::disk('public')->makeDirectory($dir);

        $uploaded = [];

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
            $url     = Storage::disk('public')->url("{$dir}/{$filename}");

            if ($isImage) {
                $thumbName = $thumbs->make("{$dir}/{$filename}");

                $uploaded[] = [
                    'url'       => $url,
                    'thumbnail' => $thumbName
                        ? Storage::disk('public')->url("{$dir}/{$thumbName}")
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

        return response()->json([
            'uploaded' => $uploaded,
        ]);
    }

    public function list(Request $request, string $modelName, int $modelId)
    {
        $class = $this->resolveModelClass($modelName);
        $model = $class::find($modelId);

        if (!$model) {
            // Нет модели (новый блок, modelId=0) — используем пустышку,
            // чтобы отработали tempImages()/tempFiles() для temp-папки.
            $model = new $class();
            $model->id = $modelId;
        }

        $tempImages = $model->tempImages();
        $tempFiles  = $model->tempFiles();

        $storedImages = $model->storedImages();
        $storedFiles  = $model->storedPlainFiles();

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

    /**
     * Удаление temp-файла. Принимает URL, извлекает имя файла.
     */
    public function deleteTemp(Request $request, string $modelName, int $modelId)
    {
        $request->validate(['url' => 'required|string']);

        $userId = auth()->id() ?? 0;
        $dir = "temp/users/{$userId}/models/{$modelName}/{$modelId}";

        $url  = $request->input('url');
        $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);

        if (!$name) {
            return response()->json(['ok' => false, 'message' => 'Не удалось определить имя файла'], 422);
        }

        $disk = Storage::disk('public');
        $path = "{$dir}/{$name}";

        if (!$disk->exists($path)) {
            return response()->json(['ok' => false, 'message' => 'Файл не найден'], 404);
        }

        // Удаляем оригинал
        $disk->delete($path);

        // Пытаемся удалить тумб — он есть только у изображений,
        // но проверять mime не нужно: thumb_<name> либо есть, либо нет.
        $thumbPath = "{$dir}/thumb_{$name}";
        if ($disk->exists($thumbPath)) {
            $disk->delete($thumbPath);
        }

        return response()->json(['ok' => true]);
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
