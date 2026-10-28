<?php
// app/Http/Controllers/Api/LessonController.php

namespace App\Http\Controllers\Api;

use App\Blocks\Settings\BlockSettingsRegistry;
use App\Blocks\Settings\SettingsNormalizer;
use App\Http\Resources\LessonBlockResource;
use App\Http\Resources\LessonListResource;
use App\Http\Resources\LessonResource;
use App\Models\Block;
use App\Models\Lesson;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LessonController
{
    public function make(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['sometimes', 'string', 'max:10'],
        ]);

        $lesson = Lesson::create([
            'user_id' => $request->user()->id,
            'locale' => $validated['locale'] ?? $request->user()->locale ?? config('app.locale'),
            'title' => '',
            'saved' => false,
            'published' => false,
        ]);

        $lesson->load('blocks');

        return response()->json(new LessonResource($lesson), 201);
    }

    public function storeBlock(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');

        abort_unless($isAdmin || $lesson->user_id === $user->id, 403);

        // Защита: запрещаем добавлять блоки в опубликованный урок
        if ($lesson->published) {
            abort(403, 'Нельзя добавлять блоки в опубликованный урок. Сначала снимите его с публикации.');
        }

        $validated = $request->validate([
            'block_id' => ['required', 'integer', 'exists:blocks,id'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $prototype = Block::findOrFail($validated['block_id']);

        $order = $validated['order']
            ?? ($lesson->blocks()->max('order') + 1);

        $block = DB::transaction(function () use ($lesson, $prototype, $order, $user) {
            if ($lesson->blocks()->where('order', '>=', $order)->exists()) {
                // Исправлено: двойные кавычки для PostgreSQL
                $lesson->blocks()
                    ->where('order', '>=', $order)
                    ->update(['order' => DB::raw('"order" + 1')]);
            }

            $block = $lesson->blocks()->create([
                'user_id' => $user->id,
                'block_id' => $prototype->id,
                'settings' => [],
                'order' => $order,
            ]);

            if (!empty($prototype->settings)) {
                $settings = $prototype->settings;
                $code = $prototype->code;

                SettingsNormalizer::syncFileSetsFromSettings($settings, $block, $code);
                $settings = SettingsNormalizer::cloneFileSetsFromModel($settings, $block, $prototype, $code);
                $block->update(['settings' => $settings]);
            }

            return $block;
        });

        $block->load('block');

        return response()->json(new LessonBlockResource($block), 201);
    }

    public function save(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');

        abort_unless($isAdmin || $lesson->user_id === $user->id, 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'saved' => ['sometimes', 'boolean'],
            'published' => ['sometimes', 'boolean'],

            'taxonomy_ids' => ['sometimes', 'array'],
            'taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],

            'blocks' => ['sometimes', 'array'],
            'blocks.*.id' => ['required_with:blocks', 'integer', 'distinct'],
            'blocks.*.settings' => ['sometimes', 'nullable', 'array'],
        ]);

        // ==================== ЗАЩИТА ОТ РЕДАКТИРОВАНИЯ ====================
        // Если урок сейчас опубликован, мы разрешаем только снятие с публикации.
        // Если в запросе нет поля published или оно равно true — блокируем.
        if ($lesson->published) {
            if (!$request->has('published') || $request->boolean('published') === true) {
                //abort(403, 'Нельзя редактировать опубликованный урок. Сначала снимите его с публикации.');
                return response()->json(new LessonResource($lesson->load(['blocks.block', 'taxonomies'])));
            }
        }
        // ===================================================================

        $result = DB::transaction(function () use ($lesson, &$validated) {
            $lesson->fill(collect($validated)->only([
                'title', 'description', 'locale', 'saved', 'published',
            ])->all());

            $lesson->save();

            // Синхронизация связей с Taxonomy
            if (array_key_exists('taxonomy_ids', $validated)) {
                $lesson->taxonomies()->sync($validated['taxonomy_ids']);
            }

            if (array_key_exists('blocks', $validated)) {
                $this->syncBlocks($lesson, $validated['blocks']);
            }

            return $lesson->load(['blocks.block', 'taxonomies']);
        });

        return response()->json(new LessonResource($result));
    }

    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');
        $isOwner = $lesson->user_id === $user->id;

        if (!$isAdmin && !$isOwner) {
            abort_unless($lesson->isSaved() && $lesson->isPublished(), 404);
        }

        $lesson->load(['blocks.block', 'taxonomies']);

        return response()->json(new LessonResource($lesson));
    }

    protected function syncBlocks(Lesson $lesson, array $blocks): void
    {
        // Исправлено: двойные кавычки для PostgreSQL
        $lesson->blocks()->update(['order' => DB::raw('"order" + 1000000')]);

        $existing = $lesson->blocks()
            ->with('block')
            ->get()
            ->keyBy('id');

        $keptIds = [];

        foreach ($blocks as $i => $data) {
            $id = $data['id'] ?? null;

            abort_unless(
                $id !== null && $existing->has($id),
                422,
                "Блок с id=" . ($id ?? 'null') . " не принадлежит уроку {$lesson->id}."
            );

            $block = $existing->get($id);
            $newSettings = $data['settings'] ?? null;

            $code = $block->block?->code;
            if ($code && $newSettings !== null) {
                SettingsNormalizer::syncFileSetsFromSettings($newSettings, $block, $code);
            }

            $block->fill([
                'settings' => SettingsNormalizer::extractPublicDefaults($newSettings, $block->block?->settings),
                'order' => $i,
            ]);
            $block->save();

            $keptIds[] = $block->id;
        }

        $toDelete = $lesson->blocks()->whereNotIn('id', $keptIds)->get();
        foreach ($toDelete as $b) {
            $b->delete();
        }
    }

    /**
     * Общий конструктор запроса для списков уроков.
     * Не фильтрует по владельцу — это делает вызывающий метод.
     */
    public function getLessonsListQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Lesson::query()
            ->withCount('blocks')
            ->with(['taxonomies:id']);

        // Фильтрация по таксономии
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

        // Поиск (BOOLEAN MODE с префиксами)
        $search = $request->input('search');
        if ($search) {
            $cleanSearch = preg_replace('/[+\-><\(\)~*\"@]+/', ' ', $search);
            $cleanSearch = trim(preg_replace('/\s+/', ' ', $cleanSearch));

            if (!empty($cleanSearch)) {
                $words = explode(' ', $cleanSearch);
                $booleanSearch = '+' . implode('* +', $words) . '*';

                // ИСправлено: Полнотекстовый поиск для PostgreSQL (вместо MATCH ... AGAINST)
                $query->whereRaw(
                    "to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(description, '')) @@ to_tsquery('simple', ?)",
                    [$booleanSearch]
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

        $paginator = $this->getLessonsListQuery($request)
            ->where('user_id', $user->id)
            ->simplePaginate($perPage);

        return response()->json([
            'data' => LessonListResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page'   => $paginator->currentPage(),
                'per_page'       => $paginator->perPage(),
                'has_more_pages' => $paginator->hasMorePages(),
                'from'           => $paginator->firstItem(),
                'to'             => $paginator->lastItem(),
            ],
        ]);
    }


}
