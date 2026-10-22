<?php
// app/Http/Controllers/Api/TaxonomyController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TaxonomyController extends Controller
{
    public function tree(): JsonResponse
    {
        // Кэшируем готовое дерево на 1 час (3600 секунд)
        $tree = Cache::remember('api_taxonomy_tree', now()->addHour(), function () {

            $items = Taxonomy::query()
                ->with('translations')
                ->orderBy('order')
                ->orderBy('id')
                ->get();

            $existingIds = $items->pluck('id')->flip()->all();
            $nodes = [];
            $parentOf = [];

            foreach ($items as $item) {
                $parentId = $item->parent_id;
                if ($parentId === null || (int) $parentId === -1 || !isset($existingIds[$parentId])) {
                    $parentId = -1;
                }
                $parentOf[$item->id] = $parentId;

                $nodes[$item->id] = [
                    'id'          => $item->id,
                    'parent_id'   => $item->parent_id,
                    'title'       => $item->title,
                    'description' => $item->description,
                    'children'    => [],
                ];
            }

            $result = [];
            foreach ($nodes as $id => &$node) {
                $parentId = $parentOf[$id];
                if ($parentId === -1) {
                    $result[] = &$node;
                } else {
                    $nodes[$parentId]['children'][] = &$node;
                }
            }
            unset($node);

            return $result;
        });

        return response()->json(['data' => $tree]);
    }
}
