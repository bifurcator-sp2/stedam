<?php
// app/Http/Controllers/Api/TestsController.php

namespace App\Http\Controllers\Api;

use App\Blocks\Settings\SettingsNormalizer;
use App\Http\Resources\BlockResource;
use App\Http\Resources\LessonBlockResource;
use App\Http\Resources\TestListResource;
use App\Models\Block;
use App\Models\LessonBlock;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestsController
{
    /**
     * Общий конструктор запроса для списков заданий.
     */
    public function getTestsListQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = LessonBlock::query()
            ->whereNull('lesson_id')
            ->with(['block:id,code,settings', 'taxonomies:id'])
            ->withCount('usages'); // Оптимальный подсчет использований

        // ==================== Фильтрация по таксономии ====================
        $taxId = $request->integer('tax');
        if ($taxId > 0) {
            $taxonomy = Taxonomy::find($taxId);
            if ($taxonomy) {
                $taxIds = $taxonomy->descendantsAndSelf()->pluck('id')->toArray();
                $query->whereHas('taxonomies', fn($q) => $q->whereIn('taxonomies.id', $taxIds));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // ==================== Поиск по содержимому JSONB (settings) ====================
        $search = $request->input('search');
        if ($search) {
            $term = trim($search);
            if ($term !== '') {
                $escapedTerm = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
                $ilike = "%{$escapedTerm}%";

                $query->whereRaw(
                    "EXISTS (
                        SELECT 1
                        FROM jsonb_path_query(COALESCE(settings, '{}'::jsonb), '$.**.default') AS val
                        WHERE val::text ILIKE ?
                    )",
                    [$ilike]
                );
            }
        }

        return $query->orderByDesc('id');
    }

    /**
     * Список заданий, доступных текущему пользователю.
     */
    public function list(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $perPage = max(1, min((int)$request->integer('per_page', 20), 100));

        $q = $this->getTestsListQuery($request);

        if (!$request->has('all')) {
            $q->where('user_id', $user->id);
        }

        $paginator = $q->simplePaginate($perPage);

        return response()->json([
            'data' => TestListResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'has_more_pages' => $paginator->hasMorePages(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /**
     * Список шаблонов заданий (опубликованных блоков с is_test = true).
     */
    public function templates(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $perPage = max(1, min((int)$request->integer('per_page', 20), 100));

        $paginator = Block::query()
            ->where('is_test', true)
            ->where('published', true)
            ->with(['translations', 'blockIndexes'])
            ->withCount('lessonBlockUsages as usages') // Считаем использования шаблона в уроках
            ->orderByDesc('id')
            ->simplePaginate($perPage);

        return response()->json([
            'data' => BlockResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'has_more_pages' => $paginator->hasMorePages(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /**
     * Создание нового задания (POST).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'block_id' => ['required', 'integer', 'exists:blocks,id'],
            'settings' => ['nullable', 'array'],
            'is_test' => ['sometimes', 'boolean'],
            'taxonomy_ids' => ['required', 'array', 'min:1'],
            'taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],
        ]);

        $block = Block::findOrFail($validated['block_id']);

        $test = DB::transaction(function () use ($user, $validated, $block) {
            $newSettings = $validated['settings'] ?? [];
            $publicSettings = SettingsNormalizer::extractPublicDefaults($newSettings, $block->settings);

            $test = LessonBlock::create([
                'user_id' => $user->id,
                'lesson_id' => null,
                'block_id' => $block->id,
                'settings' => $publicSettings,
                'order' => 0,
            ]);

            if (!empty($publicSettings)) {
                SettingsNormalizer::syncFileSetsFromSettings($publicSettings, $test, $block->code);
            }
            $test->settings = $publicSettings;
            $test->taxonomies()->sync($validated['taxonomy_ids']);

            return $test;
        });

        $test->load(['block', 'taxonomies'])->loadCount('usages');

        return response()->json(new LessonBlockResource($test), 201);
    }

    /**
     * Просмотр одного задания (GET).
     */
    public function show(Request $request, LessonBlock $lessonBlock): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && ($lessonBlock->user_id === $user->id || $user->hasRole('admin')), 403);

        $lessonBlock->load(['block', 'taxonomies'])->loadCount('usages');

        return response()->json(new LessonBlockResource($lessonBlock));
    }

    /**
     * Обновление задания (PUT / PATCH).
     */
    public function update(Request $request, LessonBlock $lessonBlock): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && ($lessonBlock->user_id === $user->id || $user->hasRole('admin')), 403);

        $validated = $request->validate([
            'settings' => ['nullable', 'array'],
            'taxonomy_ids' => ['required', 'array', 'min:1'],
            'taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],
        ]);

        DB::transaction(function () use ($lessonBlock, $validated) {
            $newSettings = $validated['settings'] ?? [];
            $publicSettings = SettingsNormalizer::extractPublicDefaults($newSettings, $lessonBlock->block->settings);

            if (!empty($publicSettings)) {
                SettingsNormalizer::syncFileSetsFromSettings($publicSettings, $lessonBlock, $lessonBlock->block->code);
            }
            $lessonBlock->settings = $publicSettings;
            $lessonBlock->save();

            $lessonBlock->taxonomies()->sync($validated['taxonomy_ids']);
        });

        $lessonBlock->load(['block', 'taxonomies'])->loadCount('usages');

        return response()->json(new LessonBlockResource($lessonBlock));
    }

    /**
     * Удаление задания (DELETE).
     */
    public function destroy(Request $request, LessonBlock $lessonBlock): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && ($lessonBlock->user_id === $user->id || $user->hasRole('admin')), 403);

        $lessonBlock->taxonomies()->sync([]);
        $lessonBlock->delete();

        return response()->json(['message' => 'Задание успешно удалено'], 200);
    }
}
