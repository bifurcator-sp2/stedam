<?php
// app/Http/Controllers/Api/TestsController.php

namespace App\Http\Controllers\Api;

use App\Blocks\Settings\SettingsNormalizer;
use App\Http\Resources\BlockResource;
use App\Http\Resources\LessonBlockResource;
use App\Http\Resources\TestListResource;
use App\Models\Block;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestsController
{
    public function getTestsListQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = LessonBlock::query()
            ->whereNull('lesson_id')
            ->with(['block:id,code,settings', 'taxonomies:id'])
            ->withCount('usages');

        if ($request->has('is_proc')) {
            $query->where('is_proc', $request->boolean('is_proc'));
        }

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
                'current_page'   => $paginator->currentPage(),
                'per_page'       => $paginator->perPage(),
                'has_more_pages' => $paginator->hasMorePages(),
                'from'           => $paginator->firstItem(),
                'to'             => $paginator->lastItem(),
            ],
        ]);
    }

    /**
     * Получение всех процедурных заданий без пагинации (сортировка по сложности).
     */
    public function list_proc(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Проверяем напрямую у конкретного узла таксономии
        $taxId = $request->integer('tax');
        if ($taxId > 0) {
            $taxonomy = Taxonomy::find($taxId);

            // Если узла нет или у него пустой test_resolver — отдаем пустой список
            if (!$taxonomy || empty($taxonomy->test_resolver)) {
                return response()->json([]);
            }
        }

        $tests = LessonBlock::query()
            ->whereNull('lesson_id')
            ->where('is_proc', true)
            ->with(['block:id,code,settings', 'taxonomies:id'])
            ->withCount('usages')
            ->orderByRaw('COALESCE(complexity, 0) ASC')
            ->get();

        return response()->json(TestListResource::collection($tests)->resolve($request));
    }

    public function templates(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $perPage = max(1, min((int)$request->integer('per_page', 20), 100));

        $q = Block::query();

        if(!$user->hasRole('admin')){
            $q->where('is_proc', false);
        }

        $paginator = $q->where('is_test', true)
            ->where('published', true)
            ->with(['translations', 'blockIndexes'])
            ->withCount('lessonBlockUsages as usages')
            ->orderByDesc('id')
            ->simplePaginate($perPage);

        return response()->json([
            'data' => BlockResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page'   => $paginator->currentPage(),
                'per_page'       => $paginator->perPage(),
                'has_more_pages' => $paginator->hasMorePages(),
                'from'           => $paginator->firstItem(),
                'to'             => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Вычисляем is_proc до валидации для динамических правил
        $isProc = $request->boolean('is_proc', false);

        $validated = $request->validate([
            'block_id'       => ['required', 'integer', 'exists:blocks,id'],
            'settings'       => ['nullable', 'array'],
            'is_test'        => ['sometimes', 'boolean'],
            'is_proc'        => ['sometimes', 'boolean'],
            // Если процедурное — таксономия не нужна, иначе обязательна
            'taxonomy_ids'   => [$isProc ? 'nullable' : 'required', 'array', 'min:' . ($isProc ? 0 : 1)],
            'taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],
            'complexity'     => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $block = Block::findOrFail($validated['block_id']);

        if($block->is_proc && !$user->hasRole('admin')){
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $test = DB::transaction(function () use ($user, $validated, $block, $isProc) {
            $newSettings    = $validated['settings'] ?? [];
            $publicSettings = SettingsNormalizer::extractPublicDefaults($newSettings, $block->settings);

            $test = LessonBlock::create([
                'user_id'    => $user->id,
                'lesson_id'  => null,
                'block_id'   => $block->id,
                'settings'   => $publicSettings,
                'order'      => 0,
                'complexity' => $validated['complexity'] ?? null,
                'is_proc'    => $isProc,
            ]);

            if (!empty($publicSettings)) {
                SettingsNormalizer::syncFileSetsFromSettings($publicSettings, $test, $block->code);
            }
            $test->settings = $publicSettings;

            // ЖЕСТКАЯ ЛОГИКА: Если процедурное - отвязываем всё. Иначе - синхронизируем.
            if ($isProc) {
                $test->taxonomies()->sync([]);
            } else {
                $test->taxonomies()->sync($validated['taxonomy_ids']);
            }

            return $test;
        });

        $test->load(['block', 'taxonomies'])->loadCount('usages');

        return response()->json(new LessonBlockResource($test), 201);
    }

    public function show(Request $request, LessonBlock $lessonBlock): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && ($lessonBlock->user_id === $user->id || $user->hasRole('admin')), 403);

        $lessonBlock->load(['block', 'taxonomies'])->loadCount('usages');

        return response()->json(new LessonBlockResource($lessonBlock));
    }

    /**
     * Обновление задания. После сохранения пересчитываем сложность всех уроков,
     * где используется это задание.
     */
    public function update(Request $request, LessonBlock $lessonBlock): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && ($lessonBlock->user_id === $user->id || $user->hasRole('admin')), 403);

        if($lessonBlock->is_proc && !$user->hasRole('admin')){
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Берем текущее значение, если в запросе не передано новое
        $isProc = $request->has('is_proc')
            ? $request->boolean('is_proc')
            : $lessonBlock->is_proc;

        $validated = $request->validate([
            'settings'       => ['nullable', 'array'],
            'is_proc'        => ['sometimes', 'boolean'],
            // Если процедурное — таксономия не нужна, иначе обязательна
            'taxonomy_ids'   => [$isProc ? 'nullable' : 'required', 'array', 'min:' . ($isProc ? 0 : 1)],
            'taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],
            'complexity'     => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        // ==================== НОВАЯ ПРОВЕРКА ====================
        if (!$isProc && $request->has('taxonomy_ids')) {
            $currentTaxIds = $lessonBlock->taxonomies()->pluck('taxonomies.id')->toArray();
            $newTaxIds = $validated['taxonomy_ids'] ?? [];

            // Сортируем массивы, чтобы сравнить их независимо от порядка
            sort($currentTaxIds);
            sort($newTaxIds);

            if ($currentTaxIds !== $newTaxIds) {
                if ($lessonBlock->usages()->exists()) {
                    abort(422, 'Нельзя сменить раздел у задания, которое уже используется в уроках.');
                }
            }
        }
        // =======================================================

        $complexityChanged = array_key_exists('complexity', $validated)
            && $validated['complexity'] != $lessonBlock->complexity;

        DB::transaction(function () use ($lessonBlock, $validated, $isProc) {
            $newSettings    = $validated['settings'] ?? [];
            $publicSettings = SettingsNormalizer::extractPublicDefaults($newSettings, $lessonBlock->block->settings);

            if (!empty($publicSettings)) {
                SettingsNormalizer::syncFileSetsFromSettings($publicSettings, $lessonBlock, $lessonBlock->block->code);
            }

            $lessonBlock->settings = $publicSettings;

            if (array_key_exists('is_proc', $validated)) {
                $lessonBlock->is_proc = $validated['is_proc'];
            }

            if (array_key_exists('complexity', $validated)) {
                $lessonBlock->complexity = $validated['complexity'];
            }

            $lessonBlock->save();

            // ЖЕСТКАЯ ЛОГИКА: Если процедурное - принудительно отвязываем старые таксономии
            if ($isProc) {
                $lessonBlock->taxonomies()->sync([]);
            } elseif (isset($validated['taxonomy_ids'])) {
                $lessonBlock->taxonomies()->sync($validated['taxonomy_ids']);
            }
        });

        // Пересчитываем среднее у всех уроков, где используется этот тест
        if ($complexityChanged) {
            $this->recalculateLessonsUsing($lessonBlock);
        }

        $lessonBlock->load(['block', 'taxonomies'])->loadCount('usages');

        return response()->json(new LessonBlockResource($lessonBlock));
    }

    public function destroy(Request $request, LessonBlock $lessonBlock): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && ($lessonBlock->user_id === $user->id || $user->hasRole('admin')), 403);

        // Запоминаем уроки до удаления — после delete связь пропадёт
        $lessonIds = $lessonBlock->usages()->pluck('lessons.id')->toArray();

        $lessonBlock->taxonomies()->sync([]);
        $lessonBlock->delete();

        // Уроки, где это задание больше не используется, должны пересчитать среднее
        if (!empty($lessonIds)) {
            Lesson::query()
                ->whereIn('id', $lessonIds)
                ->get()
                ->each(function (Lesson $lesson) {
                    $lesson->recalculateComplexity();
                    $lesson->saveQuietly();
                });
        }

        return response()->json(['message' => 'Задание успешно удалено'], 200);
    }

    /**
     * Пересчитывает complexity всех уроков, в которых используется данное задание.
     */
    protected function recalculateLessonsUsing(LessonBlock $lessonBlock): void
    {
        $lessonBlock->usages()
            ->get()
            ->each(function (Lesson $lesson) {
                $lesson->recalculateComplexity();
                $lesson->saveQuietly();
            });
    }
}
