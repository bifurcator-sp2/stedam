<?php
// app/Http/Controllers/Api/LessonController.php

namespace App\Http\Controllers\Api;

use App\Application\LessonBlocks\Actions\AddBlockAction;
use App\Application\LessonBlocks\DTOs\AddBlockData;
use App\Application\Lessons\Actions\CreateLessonAction;
use App\Application\Lessons\Actions\SaveLessonAction;
use App\Application\Lessons\DTOs\CreateLessonData;
use App\Application\Lessons\DTOs\UpdateLessonData;
use App\Application\Lessons\Exceptions\LessonAccessDeniedError;
use App\Domain\LessonBlocks\Exceptions\BlockAssignmentDenied;
use App\Domain\LessonBlocks\Exceptions\LessonBlockNotFound;
use App\Domain\LessonBlocks\Exceptions\LessonIsPublished;
use App\Domain\Lessons\Exceptions\LessonAccessDenied;
use App\Domain\Lessons\Exceptions\LessonNotFound;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\Tasks\Exceptions\TaskInUse;
use App\Domain\Tasks\Exceptions\TaxonomyRequired;
use App\Http\Resources\LessonBlockResource;
use App\Http\Resources\LessonListResource;
use App\Http\Resources\LessonResource;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\Taxonomy;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LessonController
{
    public function __construct(
        private readonly CreateLessonAction $createLesson,
        private readonly AddBlockAction $addBlock,
        private readonly SaveLessonAction $saveLesson,
    ) {}

    public function make(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['sometimes', 'string', 'max:10'],
        ]);

        $user = $request->user();

        // Выбор локали: запрос → у пользователя → app.locale.
        // CreateLessonAction подставит app.locale, если locale === null,
        // поэтому передаём «локаль владельца» как fallback здесь.
        $locale = $validated['locale'] ?? $user->locale ?? null;

        $lesson = $this->guard(fn () => ($this->createLesson)(
            new UserId((int) $user->id),
            new CreateLessonData($locale),
        ));

        $lessonId = (int) $lesson->id()->value;

        $model = Lesson::query()->findOrFail($lessonId);
        $model->load(['blocks.block', 'lessonBlockAsTests.block']);

        return response()->json(new LessonResource($model), 201);
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

        $block = $this->guard(fn () => ($this->addBlock)(
            lessonId: new LessonId((int) $lesson->id),
            actor: new UserId((int) $user->id),
            data: new AddBlockData(
                blockId: (int) $validated['block_id'],
                order: isset($validated['order']) ? (int) $validated['order'] : null,
                isAdmin: (bool) $isAdmin,
            ),
        ));

        $blockId = (int) $block->id()->value;

        // Ресурсу нужен Eloquent с relation block и выставленным pivot.order
        // для корректного поля order в ответе.
        $model = LessonBlock::query()->with('block')->findOrFail($blockId);
        $model->pivot = (object) ['order' => $block->order()];

        return response()->json(new LessonBlockResource($model), 201);
    }

    public function save(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();

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

        $data = $this->buildUpdateData($request, $validated);

        $this->guard(fn () => DB::transaction(function () use ($lesson, $data, $user) {
            ($this->saveLesson)(
                new LessonId((int) $lesson->id),
                new UserId((int) $user->id),
                $data,
            );

            // Правила урока проверяются ПОСЛЕ sync составa блоков:
            // валидатору нужен актуальный набор привязанных заданий.
            $fresh = Lesson::query()->findOrFail($lesson->id);
            $this->validateLessonRules($fresh);
        }));

        $lesson->refresh()->load(['blocks.block', 'lessonBlockAsTests.block', 'taxonomies']);

        return response()->json(new LessonResource($lesson));
    }

    /**
     * Собрать DTO из провалидированных данных с учётом «touch»-флагов.
     *
     * Description обрабатывается отдельно: null — валидное значение,
     * поэтому нужен presence-флаг.
     */
    private function buildUpdateData(Request $request, array $validated): UpdateLessonData
    {
        return new UpdateLessonData(
            title: array_key_exists('title', $validated) ? (string) ($validated['title'] ?? '') : null,
            description: array_key_exists('description', $validated) ? ($validated['description'] ?? null) : null,
            descriptionTouched: $request->has('description'),
            locale: $validated['locale'] ?? null,
            saved: array_key_exists('saved', $validated) ? (bool) $validated['saved'] : null,
            published: array_key_exists('published', $validated) ? (bool) $validated['published'] : null,
            taxonomyIds: array_key_exists('taxonomy_ids', $validated)
                ? array_map('intval', (array) $validated['taxonomy_ids'])
                : null,
            blocks: array_key_exists('blocks', $validated) ? (array) $validated['blocks'] : null,
        );
    }

    /**
     * Проброс доменных ошибок в HTTP-ответы.
     *
     * Маппинг сохранён 1:1 с поведением старого контроллера:
     *   — доступ/публикация → 403 (как abort(403));
     *   — правила/ассайнмент → 422 (как abort(422));
     *   — отсутствие → 404.
     */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (LessonAccessDenied | LessonAccessDeniedError $e) {
            abort(403, $e->getMessage());
        } catch (LessonIsPublished $e) {
            abort(403, $e->getMessage());
        } catch (BlockAssignmentDenied | TaxonomyRequired | TaskInUse $e) {
            abort(422, $e->getMessage());
        } catch (LessonNotFound | LessonBlockNotFound $e) {
            abort(404, $e->getMessage());
        } catch (DomainException $e) {
            // Остальные доменные нарушения — 422 (валидационные по смыслу).
            abort(422, $e->getMessage());
        }
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
     *
     * Вызывается из save() ПОСЛЕ SaveLessonAction (sync блоков),
     * внутри той же транзакции. Логика идентична старой версии.
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
