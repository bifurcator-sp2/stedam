<?php
// app/Http/Controllers/FilesController.php

namespace App\Http\Controllers;

use App\Enums\FilePurpose;
use Bifurcator\FileSets\FilesService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FilesController extends Controller
{
    public function __construct(
        protected FilesService $files,
    ) {}

    // ============================================================
    // PRELOAD
    // ============================================================

    public function preload(
        Request $request,
        string $modelName,
        int $modelId,
    ) {
        $class = $this->resolveModelClass($modelName);
        $rules = $this->rulesFor($class);

        $maxImages = (int) config('file-sets.max_images', 100);
        $maxFiles  = (int) config('file-sets.max_files', 20);

        $request->validate([
            'files'   => 'required|array|max:' . ($maxImages + $maxFiles),
            'files.*' => 'file|max:' . ($rules['max_size_kb'] ?? 10240),
            'purpose' => ['nullable', 'string', Rule::in(FilePurpose::values())],
        ]);

        try {
            $uploaded = $this->files->preload(
                $request->file('files'),
                $class,
                $modelId,
                $request->query('purpose'),
                $rules,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'files' => $e->getMessage(),
            ]);
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

        $class = $this->resolveModelClass($modelName);

        $result = $this->files->list(
            $class,
            $modelId,
            $request->query('purpose'),
        );

        return response()->json($result);
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

        $class = $this->resolveModelClass($modelName);

        $ok = $this->files->deleteTemp(
            $class,
            $modelId,
            $request->input('url'),
            $request->query('purpose'),
        );

        if (!$ok) {
            return response()->json([
                'ok'      => false,
                'message' => 'Файл не найден',
            ], 404);
        }

        return response()->json(['ok' => true]);
    }

    // ============================================================
    // Хелперы
    // ============================================================

    /**
     * Возвращает $fileRules модели или дефолтные.
     */
    private function rulesFor(string $class): array
    {
        return $class::$fileRules ?? [
                'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                'file_mimes'  => ['application/pdf'],
                'max_images'  => 10,
                'max_files'   => 10,
                'max_size_kb' => 10240,
            ];
    }

    /**
     * Преобразует "lesson_block" → "App\Models\LessonBlock".
     */
    private function resolveModelClass(string $modelName): string
    {
        $studly = str($modelName)->studly()->singular()->toString();
        return "App\\Models\\{$studly}";
    }
}
