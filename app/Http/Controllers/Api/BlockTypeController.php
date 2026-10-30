<?php
// app/Http/Controllers/Api/BlockTypeController.php

namespace App\Http\Controllers\Api;

use App\Blocks\Settings\BlockSettingsRegistry;
use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\BlockIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BlockTypeController extends Controller
{
    /**
     * GET /api/block-types
     * Возвращает список доступных типов блоков (для админки).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $items = collect(BlockSettingsRegistry::codes())->map(function ($code) {
            $meta = BlockSettingsRegistry::getMeta($code);

            return [
                'code' => $code,
                'type' => 'info', // Условный тип
                'name' => $meta['name'] ?? $code,
                'description' => $meta['description'] ?? null,
                'icon' => $meta['icon'] ?? 'i-lucide-square',
                'default_settings' => BlockSettingsRegistry::serializeSchema($code),
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

        // 1. Загружаем блоки (только опубликованные)
        $blocks = Block::query()
            ->with(['translations' => fn($q) => $q->whereIn('locale', [$locale, $fallback])])
            ->where('published', true)
            ->where('is_test', false)
            ->get();

        // Форматируем блоки, добавляя им default_settings и code типа
        $formattedBlocks = $blocks->map(function (Block $b) use ($locale, $fallback) {
            $t = $b->translations->firstWhere('locale', $locale)
                ?? $b->translations->firstWhere('locale', $fallback)
                ?? $b->translations->first();

            return [
                'id' => $b->id,
                'block_type_code' => $b->code,
                'default_settings' => BlockSettingsRegistry::serializeSchema($b->code),
                'title' => $t?->title,
                'description' => $t?->description,
                'settings' => $b->settings ?? [],
                'is_test' => (bool) $b->is_test, // <--- ДОБАВЛЕНО
            ];
        })->values();

        // 2. Загружаем привязки блоков к узлам
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

        // 3. Загружаем все узлы BlockIndex (дерево)
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
            $nodeBlocks = collect($nodeBlockIds)
                ->map(fn($id) => $formattedBlocks->firstWhere('id', $id))
                ->filter()
                ->values();

            $nodesById[$index->id] = [
                'id' => $index->id,
                'parent_id' => $index->parent_id,
                'code' => 'block_index_' . $index->id,
                'type' => 'info',
                'name' => $t?->title ?? 'Без названия',
                'description' => $t?->description,
                'default_settings' => null,
                'blocks_count' => $nodeBlocks->count(),
                'blocks' => $nodeBlocks,
                'children' => [],
            ];
        }

        // 4. Собираем дерево
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

        // 5. Блоки без привязки → "Разное"
        $miscBlocks = $formattedBlocks->filter(fn($b) => !isset($attachedBlockIds[$b['id']]))->values();
        if ($miscBlocks->isNotEmpty()) {
            $tree[] = [
                'id' => -1,
                'parent_id' => null,
                'code' => 'misc',
                'type' => 'info',
                'name' => 'Разное',
                'description' => 'Блоки общего назначения',
                'default_settings' => null,
                'blocks_count' => $miscBlocks->count(),
                'blocks' => $miscBlocks,
                'children' => [],
            ];
        }

        return response()->json(['data' => $tree]);
    }
}
