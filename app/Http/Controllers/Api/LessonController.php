<?php
// app/Http/Controllers/Api/LessonController.php

namespace App\Http\Controllers\Api;

use App\Domain\Blocks\Settings\SettingsNormalizer;
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
                    'is_proc'   => $prototype->is_proc ?? false,
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
                $lesson->recalculateComplexity();
                $lesson->saveQuietly();
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

        abort_unless($lesson->user_id === $user->id, 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'saved' => ['sometimes', 'boolean'],
            'published' => ['sometimes', 'boolean'],

            'taxonomy_ids' => ['sometimes', 'array'],
            'taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],

            'blocks' => ['sometimes', 'array'],
            'blocks.*.id' => ['required_with:blocks', 'integer'],
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

            // Синхронизация связей с Taxonomy
            if (array_key_exists('taxonomy_ids', $validated)) {
                $lesson->taxonomies()->sync($validated['taxonomy_ids']);
            }

            if (array_key_exists('blocks', $validated)) {
                $this->syncBlocks($lesson, $validated['blocks']);
            }

            // ВАЛИДАЦИЯ ПРАВИЛ УРОКА
            $this->validateLessonRules($lesson);

            $lesson->save();
            return $lesson->load(['blocks.block', 'lessonBlockAsTests.block', 'taxonomies']);
        });

        return response()->json(new LessonResource($result));
    }

    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');
        $isOwner = $lesson->user_id === $user->id;

        if (!$isOwner) {
            abort_unless($lesson->isSaved() && $lesson->isPublished(), 404);
        }

        $lesson->load(['blocks.block', 'lessonBlockAsTests.block', 'taxonomies']);

        return response()->json(new LessonResource($lesson));
    }

    /**
     * Валидация правил урока перед сохранением.
     * Не мутирует объект $lesson.
     */
    protected function validateLessonRules(Lesson $lesson): void
    {
        // Запрашиваем связи напрямую из БД, не изменяя состояние объекта $lesson
        $taxonomies = $lesson->taxonomies()->get();

        // Задания урока (pivot lesson_lesson_block_as_test).
        // Собственные info-блоки урока (lesson_id = N) здесь не нужны —
        // они не валидируются на таксономию.
        $testBlocks = $lesson->lessonBlockAsTests()->with(['block', 'taxonomies'])->get();

        // 1. Собираем все валидные ID таксономий урока (с учетом потомков)
        $lessonTaxIds = $taxonomies->pluck('id')->toArray();
        $validLessonTaxIds = [];
        if (!empty($lessonTaxIds)) {
            $validLessonTaxIds = Taxonomy::whereIn('id', $lessonTaxIds)
                ->get()
                ->flatMap(fn($t) => $t->descendantsAndSelf()->pluck('id'))
                ->unique()
                ->toArray();
        }

        // 2. Проверяем наличие test_resolver у таксономии урока (для процедурных заданий)
        $hasResolver = false;
        foreach ($taxonomies as $taxonomy) {
            $node = $taxonomy;
            $guard = 0;
            while ($node !== null && $guard++ < 64) {
                if (!empty($node->test_resolver)) {
                    $hasResolver = true;
                    break 2;
                }
                if ($node->parent_id === null || $node->parent_id < 0) {
                    break;
                }
                $node = Taxonomy::find($node->parent_id);
            }
        }

        // 3. Валидация каждого ЗАДАНИЯ.
        //
        // ВАЖНО: проверять надо РОЛЬ в уроке, а не Block::is_test.
        //   - собственные info-блоки урока (lesson_id = N) — не валидируем:
        //     таксономия им не нужна;
        //   - задания из lesson_lesson_block_as_test — валидируем ниже.
        // Block::is_test — атрибут ШАБЛОНА, а не роль в уроке:
        // шаблон с is_test = true может использоваться и как info-блок.
        foreach ($testBlocks as $block) {
            // Условие 1: проверка таксономии задания
            if (!empty($validLessonTaxIds)) {
                $blockTaxIds = $block->taxonomies->pluck('id')->toArray();
                if (!empty($blockTaxIds)) {
                    if (empty(array_intersect($blockTaxIds, $validLessonTaxIds))) {
                        abort(422, "Задание (ID: {$block->id}) не соответствует таксономии урока.");
                    }
                } else {
                    // Исключение только для процедурных заданий
                    if (!$block->is_proc) {
                        abort(422, "Задание (ID: {$block->id}) не привязано ни к одному разделу таксономии.");
                    }
                }
            }

            // Условие 2: проверка процедурного задания
            if ($block->is_proc) {
                if (!$hasResolver) {
                    abort(422, "Урок содержит процедурное задание (ID: {$block->id}), но урок относится к разделу знаний, который не имеет герератора заданий.");
                }
            }
        }
    }

    protected function syncBlocks(Lesson $lesson, array $blocks): void
    {
        $currentUserId = auth()->id();

        // Сдвигаем только собственные блоки урока в безопасную зону
        $lesson->blocks()->update(['order' => DB::raw('"order" + 1000000')]);

        // Удаляем ВСЕ старые привязки заданий к этому уроку
        DB::table('lesson_lesson_block_as_test')
            ->where('lesson_id', $lesson->id)
            ->delete();

        $existingOwn = $lesson->blocks()
            ->with('block')
            ->get()
            ->keyBy('id');

        $keptOwnIds = [];

        foreach ($blocks as $i => $data) {
            $id          = $data['id'] ?? null;
            $newSettings = $data['settings'] ?? null;

            abort_unless($id !== null, 422, 'У блока отсутствует id.');

            // 1) Собственный блок урока
            if ($existingOwn->has($id)) {
                $block = $existingOwn->get($id);

                if ($newSettings !== null) {
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

            if ($newSettings !== null) {
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

            // Обычный INSERT (мы удалили старые записи в начале).
            // Теперь можно привязать одно и то же задание несколько раз (разный $i).
            DB::table('lesson_lesson_block_as_test')->insert([
                'lesson_id'       => $lesson->id,
                'lesson_block_id' => $shared->id,
                'order'           => $i,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        // Удаляем собственные блоки, которых нет в запросе
        $lesson->blocks()
            ->whereNotIn('id', $keptOwnIds)
            ->get()
            ->each
            ->delete();

        // Pivot-строки уже пересозданы выше, удалять их в конце не нужно.
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
