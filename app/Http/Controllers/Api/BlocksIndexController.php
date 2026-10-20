<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockIndex;
use Illuminate\Http\JsonResponse;

class BlocksIndexController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $items = BlockIndex::query()
            ->with([
                'translations',
                'blocks.translations', // pivot order уже отсортирован в отношении
            ])
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        $existingIds = $items->pluck('id')->flip()->all();

        // ── Шаг 1: плоский список узлов + карта "эффективный родитель" ──
        $nodes    = [];
        $parentOf = [];

        foreach ($items as $item) {
            $parentId = $item->parent_id;

            if ($parentId === null || (int) $parentId === -1 || !isset($existingIds[$parentId])) {
                $parentId = -1;
            }

            $parentOf[$item->id] = $parentId;

            $directCount = $item->blocks->count();

            $nodes[$item->id] = [
                'id'                 => $item->id,
                'parent_id'          => $item->parent_id,
                'order'              => $item->order,
                'title'              => $item->title,
                'description'        => $item->description,

                'blocks'             => $item->blocks->map(fn ($b) => [
                    'id'            => $b->id,
                    'block_type_id' => $b->block_type_id,
                    'order'         => $b->pivot->order,
                    'title'         => $b->title,        // accessor: translations + locale + fallback
                    'description'   => $b->description,  // accessor: translations + locale + fallback
                ])->values()->all(),

                'blocks_count'       => $directCount,
                'blocks_count_total' => $directCount,
                'children'           => [],
            ];
        }

        // ── Шаг 2: сборка дерева по ссылкам — O(n), любая глубина ──
        $tree = [];
        foreach ($nodes as $id => &$node) {
            $parentId = $parentOf[$id];

            if ($parentId === -1) {
                $tree[] = &$node;
            } else {
                $nodes[$parentId]['children'][] = &$node;
            }
        }
        unset($node);

        // ── Шаг 3: суммарные счётчики (self + всё поддерево) ──
        $depth = [];
        foreach ($nodes as $id => $_) {
            $d = 0;
            $p = $parentOf[$id];
            while ($p !== -1) {
                $d++;
                $p = $parentOf[$p];
            }
            $depth[$id] = $d;
        }

        $ids = array_keys($nodes);
        usort($ids, fn ($a, $b) => $depth[$b] <=> $depth[$a]);

        foreach ($ids as $id) {
            $p = $parentOf[$id];
            if ($p !== -1) {
                $nodes[$p]['blocks_count_total'] += $nodes[$id]['blocks_count_total'];
            }
        }

        return response()->json([
            'data' => $tree,
        ]);
    }
}
