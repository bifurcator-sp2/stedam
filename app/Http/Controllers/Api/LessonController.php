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
use App\Models\LessonBlock;
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

        $lesson->load(['blocks.block', 'lessonBlockAsTests.block']);

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
            'order'    => ['sometimes', 'integer', 'min:0'],
        ]);

        $prototype = Block::findOrFail($validated['block_id']);

        // =====================================================================
        // 1. ЛОГИКА ДЛЯ БЛОКОВ-ЗАДАНИЙ (определяем по прототипу)
        // =====================================================================
        if ($prototype->is_test) {
            $block = DB::transaction(function () use ($lesson, $prototype, $validated, $user) {
                // Всегда создаем НОВЫЙ экземпляр задания (глобальный)
                $sharedBlock = LessonBlock::create([
                    'user_id'   => $user->id,
                    'block_id'  => $prototype->id,
                    'settings'  => $prototype->settings ?? [],
                    'lesson_id' => null,
                ]);

                // Клонируем файлы из прототипа
                if (!empty($prototype->settings)) {
                    $settings = $prototype->settings;
                    $code = $prototype->code;

                    SettingsNormalizer::syncFileSetsFromSettings($settings, $sharedBlock, $code);
                    $settings = SettingsNormalizer::cloneFileSetsFromModel($settings, $sharedBlock, $prototype, $code);
                    $sharedBlock->update(['settings' => $settings]);
                }

                // Вычисляем order (учитываем и собственные блоки, и другие задания)
                $maxOwn = $lesson->blocks()->max('order') ?? -1;
                $maxTest = DB::table('lesson_lesson_block_as_test')->where('lesson_id', $lesson->id)->max('order') ?? -1;
                $order = $validated['order'] ?? (max($maxOwn, $maxTest) + 1);

                // БЕЗОПАСНЫЙ СДВИГ ORDER ДЛЯ POSTGRESQL
                $hasOwn = $lesson->blocks()->where('order', '>=', $order)->exists();
                $hasTest = DB::table('lesson_lesson_block_as_test')->where('lesson_id', $lesson->id)->where('order', '>=', $order)->exists();

                if ($hasOwn) {
                    $lesson->blocks()->where('order', '>=', $order)->update(['order' => DB::raw('"order" + 1000000')]);
                }
                if ($hasTest) {
                    DB::table('lesson_lesson_block_as_test')->where('lesson_id', $lesson->id)->where('order', '>=', $order)->update(['order' => DB::raw('"order" + 1000000')]);
                }

                // Привязываем к уроку
                DB::table('lesson_lesson_block_as_test')->insert([
                    'lesson_id'       => $lesson->id,
                    'lesson_block_id' => $sharedBlock->id,
                    'order'           => $order,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                // ВОЗВРАЩАЕМ СДВИНУТЫЕ ЭЛЕМЕНТЫ НА +1
                if ($hasOwn) {
                    $lesson->blocks()->where('order', '>=', 1000000)->update(['order' => DB::raw('"order" - 999999')]);
                }
                if ($hasTest) {
                    DB::table('lesson_lesson_block_as_test')->where('lesson_id', $lesson->id)->where('order', '>=', 1000000)->update(['order' => DB::raw('"order" - 999999')]);
                }

                // Устанавливаем pivot вручную, чтобы LessonBlockResource корректно отдал order
                $sharedBlock->pivot = (object)['order' => $order];

                return $sharedBlock;
            });

            $block->load('block');

            return response()->json(new LessonBlockResource($block), 201);
        }

        // =====================================================================
        // 2. ЛОГИКА ДЛЯ ОБЫЧНЫХ БЛОКОВ (как было раньше)
        // =====================================================================
        $order = $validated['order']
            ?? ($lesson->blocks()->max('order') + 1);

        $block = DB::transaction(function () use ($lesson, $prototype, $order, $user) {
            // БЕЗОПАСНЫЙ СДВИГ ORDER ДЛЯ POSTGRESQL
            $hasOwn = $lesson->blocks()->where('order', '>=', $order)->exists();

            if ($hasOwn) {
                $lesson->blocks()->where('order', '>=', $order)->update(['order' => DB::raw('"order" + 1000000')]);
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

            // ВОЗВРАЩАЕМ СДВИНУТЫЕ ЭЛЕМЕНТЫ НА +1
            if ($hasOwn) {
                $lesson->blocks()->where('order', '>=', 1000000)->update(['order' => DB::raw('"order" - 999999')]);
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
        if ($lesson->published) {
            if (!$request->has('published') || $request->boolean('published') === true) {
                return response()->json(new LessonResource($lesson->load(['blocks.block', 'lessonBlockAsTests.block', 'taxonomies'])));
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

            return $lesson->load(['blocks.block', 'lessonBlockAsTests.block', 'taxonomies']);
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

        $lesson->load(['blocks.block', 'lessonBlockAsTests.block', 'taxonomies']);

        return response()->json(new LessonResource($lesson));
    }

    protected function syncBlocks(Lesson $lesson, array $blocks): void
    {
        $currentUserId = auth()->id();

        // Сдвигаем оба «слоя» в безопасную зону
        $lesson->blocks()->update(['order' => DB::raw('"order" + 1000000')]);
        DB::table('lesson_lesson_block_as_test')
            ->where('lesson_id', $lesson->id)
            ->update(['order' => DB::raw('"order" + 1000000')]);

        // Собственные блоки урока (lesson_id = $lesson->id)
        $existingOwn = $lesson->blocks()
            ->with('block')
            ->get()
            ->keyBy('id');

        $keptOwnIds    = [];
        $keptSharedIds = [];

        foreach ($blocks as $i => $data) {
            $id          = $data['id'] ?? null;
            $newSettings = $data['settings'] ?? null;

            abort_unless($id !== null, 422, 'У блока отсутствует id.');

            // 1) Собственный блок урока
            if ($existingOwn->has($id)) {
                $block = $existingOwn->get($id);

                // Применяем настройки только если блок принадлежит текущему пользователю
                if ($block->user_id === $currentUserId && $newSettings !== null) {
                    $code = $block->block?->code;
                    if ($code) {
                        SettingsNormalizer::syncFileSetsFromSettings($newSettings, $block, $code);
                    }

                    $block->settings = SettingsNormalizer::extractPublicDefaults(
                        $newSettings,
                        $block->block?->settings
                    );
                }

                $block->order = $i;
                $block->save();

                $keptOwnIds[] = $block->id;
                continue;
            }

            // 2) «Задание» — LessonBlock с lesson_id = null, шарится между уроками
            $shared = LessonBlock::query()
                ->whereNull('lesson_id')
                ->with('block')
                ->find($id);

            abort_unless(
                $shared !== null,
                422,
                "Блок с id={$id} не принадлежит уроку {$lesson->id} и не является заданием."
            );

            // Если задание принадлежит текущему пользователю, применяем стандартные обработки настроек
            if ($shared->user_id === $currentUserId && $newSettings !== null) {
                $code = $shared->block?->code;
                if ($code) {
                    SettingsNormalizer::syncFileSetsFromSettings($newSettings, $shared, $code);
                }

                $shared->settings = SettingsNormalizer::extractPublicDefaults(
                    $newSettings,
                    $shared->block?->settings
                );
                $shared->save();
            }

            // В любом случае обновляем позицию в пивоте (привязка/отвязка и порядок)
            DB::table('lesson_lesson_block_as_test')->upsert(
                [[
                    'lesson_id'       => $lesson->id,
                    'lesson_block_id' => $shared->id,
                    'order'           => $i,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]],
                ['lesson_id', 'lesson_block_id'],   // уникальный ключ
                ['order', 'updated_at']             // что обновлять при конфликте
            );

            $keptSharedIds[] = $shared->id;
        }

        // Удаляем собственные блоки, которых нет в запросе
        $lesson->blocks()
            ->whereNotIn('id', $keptOwnIds)
            ->get()
            ->each
            ->delete();

        // Отвязываем «задания», которых нет в запросе — сами LessonBlock не удаляем
        DB::table('lesson_lesson_block_as_test')
            ->where('lesson_id', $lesson->id)
            ->whereNotIn('lesson_block_id', $keptSharedIds)
            ->delete();
    }

    /**
     * Общий конструктор запроса для списков уроков.
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

        // Поиск
        $search = $request->input('search');
        if ($search) {
            $cleanSearch = preg_replace('/[+\-><\(\)~*\"@]+/', ' ', $search);
            $cleanSearch = trim(preg_replace('/\s+/', ' ', $cleanSearch));

            if (!empty($cleanSearch)) {
                $words = explode(' ', $cleanSearch);
                $booleanSearch = '+' . implode('* +', $words) . '*';

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
