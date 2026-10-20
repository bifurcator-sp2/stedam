<?php
// app/Http/Controllers/Api/LessonController.php

namespace App\Http\Controllers\Api;

use App\Blocks\Settings\SettingsNormalizer;
use App\Http\Resources\LessonBlockResource;
use App\Http\Resources\LessonResource;
use App\Models\Block;
use App\Models\Lesson;
use App\Models\LessonBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LessonController
{
    public function make(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['sometimes', 'string', 'max:10'],
        ]);

        $lesson = Lesson::create([
            'user_id'   => $request->user()->id,
            'locale'    => $validated['locale'] ?? $request->user()->locale ?? config('app.locale'),
            'title'     => '',
            'saved'     => false,
            'published' => false,
        ]);

        $lesson->load('blocks');

        return response()->json(new LessonResource($lesson), 201);
    }

    public function storeBlock(Request $request, Lesson $lesson): JsonResponse
    {
        $user    = $request->user();
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

        $prototype = Block::with('blockType')->findOrFail($validated['block_id']);

        $order = $validated['order']
            ?? ($lesson->blocks()->max('order') + 1);

        $block = DB::transaction(function () use ($lesson, $prototype, $order, $user) {
            if ($lesson->blocks()->where('order', '>=', $order)->exists()) {
                $lesson->blocks()
                    ->where('order', '>=', $order)
                    ->update(['order' => DB::raw('`order` + 1')]);
            }

            $block = $lesson->blocks()->create([
                'user_id'  => $user->id,
                'block_id' => $prototype->id,
                'settings' => [],
                'order'    => $order,
            ]);

            if (!empty($prototype->settings)) {
                $settings = $prototype->settings;
                $code = $prototype->blockType?->code;

                $block->syncFileSetsFromSettings($settings, $code);
                $settings = $block->cloneFileSetsFromModel($prototype, $settings, $code);
                $block->update(['settings' => $settings]);
            }

            return $block;
        });

        $block->load('block.blockType');

        return response()->json(new LessonBlockResource($block), 201);
    }

    public function save(Request $request, Lesson $lesson): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = $user->hasRole('admin');

        abort_unless($isAdmin || $lesson->user_id === $user->id, 403);

        $validated = $request->validate([
            'title'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'locale'      => ['sometimes', 'string', 'max:10'],
            'saved'       => ['sometimes', 'boolean'],
            'published'   => ['sometimes', 'boolean'],

            'taxonomy_ids'   => ['sometimes', 'array'],
            'taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],

            'blocks'            => ['sometimes', 'array'],
            'blocks.*.id'       => ['required_with:blocks', 'integer', 'distinct'],
            'blocks.*.settings' => ['sometimes', 'nullable', 'array'],
        ]);

        // ==================== ЗАЩИТА ОТ РЕДАКТИРОВАНИЯ ====================
        // Если урок сейчас опубликован, мы разрешаем только снятие с публикации.
        // Если в запросе нет поля published или оно равно true — блокируем.
        if ($lesson->published) {
            if (!$request->has('published') || $request->boolean('published') === true) {
                abort(403, 'Нельзя редактировать опубликованный урок. Сначала снимите его с публикации.');
            }
        }
        // ===================================================================

        $result = DB::transaction(function () use ($lesson, &$validated, $user) {
            $lesson->fill(collect($validated)->only([
                'title', 'description', 'locale', 'saved', 'published',
            ])->all());

            $lesson->save();

            // Синхронизация связей с Taxonomy
            if (array_key_exists('taxonomy_ids', $validated)) {
                $lesson->taxonomies()->sync($validated['taxonomy_ids']);
            }

            if (array_key_exists('blocks', $validated)) {
                $this->syncBlocks($lesson, $validated['blocks'], $user);
            }

            return $lesson->load(['blocks.block.blockType', 'taxonomies']);
        });

        return response()->json(new LessonResource($result));
    }

    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = $user->hasRole('admin');
        $isOwner = $lesson->user_id === $user->id;

        if (!$isAdmin && !$isOwner) {
            abort_unless($lesson->isSaved() && $lesson->isPublished(), 404);
        }

        $lesson->load(['blocks.block.blockType', 'taxonomies']);

        return response()->json(new LessonResource($lesson));
    }

    protected function syncBlocks(Lesson $lesson, array $blocks, $user): void
    {
        $lesson->blocks()->update(['order' => DB::raw('`order` + 1000000')]);

        $existing = $lesson->blocks()
            ->with('block.blockType')
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

            $code = $block->block?->blockType?->code;
            if ($code && $newSettings !== null) {
                $block->syncFileSetsFromSettings($newSettings, $code);
            }

            $block->fill([
                'settings' => SettingsNormalizer::extractPublicValues($newSettings, $block->block->settings),
                'order'    => $i,
            ]);
            $block->save();

            $keptIds[] = $block->id;
        }

        $toDelete = $lesson->blocks()->whereNotIn('id', $keptIds)->get();
        foreach ($toDelete as $b) {
            $b->delete();
        }
    }

    public function list(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $paginator = Lesson::query()
            ->withCount('blocks')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Lesson $l) => [
                'id'          => $l->id,
                'title'       => $l->title,
                'description' => $l->description,
                'blocks_count'=> $l->blocks_count,
                'saved'       => (bool) $l->saved,
                'published'   => (bool) $l->published,
                'created_at'  => $l->created_at?->toISOString(),
                'updated_at'  => $l->updated_at?->toISOString(),
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ]);
    }
}
