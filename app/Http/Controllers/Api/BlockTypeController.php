<?php
// app/Http/Controllers/Api/BlockTypeController.php

namespace App\Http\Controllers\Api;

use App\Blocks\Settings\BlockSettingsRegistry;
use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\BlockIndex;
use App\Models\BlockType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BlockTypeController extends Controller
{
    /**
     * GET /api/block-types
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        $query = BlockType::query()
            ->with(['translations' => function ($q) use ($locale, $fallback) {
                $q->whereIn('locale', [$locale, $fallback]);
            }])
            ->orderBy('code');

        $items = $query->get()->map(function (BlockType $type) use ($locale, $fallback) {
            $translation = $type->translations->firstWhere('locale', $locale)
                ?? $type->translations->firstWhere('locale', $fallback)
                ?? $type->translations->first();

            return [
                'id' => $type->id,
                'code' => $type->code,
                'type' => $type->type,
                'name' => $translation?->name ?? $type->code,
                'description' => $translation?->description,
                'default_settings' => $type->normalizedSettings,
            ];
        })->values();

        return response()->json($items);
    }

    /**
     * GET /api/block-types/struct
     *
     * Возвращает дерево узлов BlockIndex, внутри которых сгруппированы блоки.
     * Блоки без привязки к узлам попадают в "Разное".
     */
    public function struct(Request $request): JsonResponse
    {
        $user = $request->user();
        $locale   = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        // 1. Загружаем типы блоков (для default_settings)
        $types = BlockType::query()
            ->with(['translations' => fn($q) => $q->whereIn('locale', [$locale, $fallback])])
            ->get()
            ->keyBy('id');

        // 2. Загружаем блоки пользователя (только опубликованные)
        $blocks = Block::query()
            ->with(['translations' => fn($q) => $q->whereIn('locale', [$locale, $fallback])])
            ->where('user_id', $user->id)
            ->where('published', true) // <-- ДОБАВЛЕНО
            ->get();

        // Форматируем блоки, добавляя им default_settings и code типа
        $formattedBlocks = $blocks->map(function (Block $b) use ($locale, $fallback, $types) {
            $t = $b->translations->firstWhere('locale', $locale)
                ?? $b->translations->firstWhere('locale', $fallback)
                ?? $b->translations->first();

            $type = $types->get($b->block_type_id);

            return [
                'id' => $b->id,
                'block_type_id' => $b->block_type_id,
                'block_type_code' => $type?->code ?? 'unknown',
                'default_settings' => $type?->normalizedSettings ?? [],
                'title' => $t?->title,
                'description' => $t?->description,
                'settings' => $b->settings ?? [],
            ];
        })->values();

        // 3. Загружаем привязки блоков к узлам
        $pivot = DB::table('block_block_index')
            ->whereIn('block_id', $blocks->pluck('id'))
            ->get()
            ->groupBy('block_id');

        $blocksByIndex = [];
        $attachedBlockIds = [];

        foreach ($pivot as $blockId => $relations) {
            foreach ($relations as $rel) {
                $blocksByIndex[$rel->block_index_id][] = $blockId;
                $attachedBlockIds[$blockId] = true;
            }
        }

        // 4. Загружаем все узлы BlockIndex (дерево)
        $indices = BlockIndex::query()
            ->with(['translations' => fn($q) => $q->whereIn('locale', [$locale, $fallback])])
            ->orderBy('order')
            ->get();

        $nodesById = [];

        foreach ($indices as $index) {
            $t = $index->translations->firstWhere('locale', $locale)
                ?? $index->translations->firstWhere('locale', $fallback)
                ?? $index->translations->first();

            $nodeBlockIds = $blocksByIndex[$index->id] ?? [];
            $nodeBlocks = collect($nodeBlockIds)->map(fn($id) => $formattedBlocks->firstWhere('id', $id))->filter()->values();

            $nodesById[$index->id] = [
                'id' => $index->id,
                'parent_id' => $index->parent_id,
                'code' => 'block_index_' . $index->id,
                'type' => 'info',
                'name' => $t?->title ?? 'Без названия',
                'description' => $t?->description,
                'default_settings' => [],
                'blocks_count' => $nodeBlocks->count(),
                'blocks' => $nodeBlocks,
                'children' => [],
            ];
        }

        // 5. Собираем дерево
        $tree = [];
        foreach ($nodesById as $id => &$node) {
            $parentId = $node['parent_id'];
            if ($parentId === -1 || $parentId === null || !isset($nodesById[$parentId])) {
                $tree[] = &$node;
            } else {
                $nodesById[$parentId]['children'][] = &$node;
            }
        }
        unset($node);

        // 6. Блоки без привязки → "Разное"
        $miscBlocks = $formattedBlocks->filter(fn($b) => !isset($attachedBlockIds[$b['id']]))->values();
        if ($miscBlocks->isNotEmpty()) {
            $tree[] = [
                'id' => -1,
                'parent_id' => null,
                'code' => 'misc',
                'type' => 'info',
                'name' => 'Разное',
                'description' => 'Блоки общего назначения',
                'default_settings' => [],
                'blocks_count' => $miscBlocks->count(),
                'blocks' => $miscBlocks,
                'children' => [],
            ];
        }

        return response()->json(['data' => $tree]);
    }

    // ... метод show без изменений ...
}
