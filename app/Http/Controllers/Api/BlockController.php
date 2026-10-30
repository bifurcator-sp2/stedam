<?php
// app/Http/Controllers/Api/BlockController.php

namespace App\Http\Controllers\Api;

use App\Blocks\Settings\BlockSettingsRegistry;
use App\Blocks\Settings\SettingsNormalizer;
use App\Http\Controllers\Controller;
use App\Http\Resources\BlockResource;
use App\Models\Block;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BlockController extends Controller
{
    public function template(string $code): JsonResponse
    {
        if (!BlockSettingsRegistry::has($code)) {
            abort(404, 'Block type not found');
        }

        $block = new Block();
        $block->code = $code;
        $block->settings = BlockSettingsRegistry::serializeSchema($code);

        return response()->json(new BlockResource($block));
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $paginator = Block::query()
            ->with(['translations', 'blockIndexes'])
            ->where('user_id', $user->id)
            ->when($request->filled('code'),
                fn ($q) => $q->where('code', $request->input('code')))
            ->when($request->filled('title'),
                fn ($q) => $q->whereHas('translations',
                    fn ($tq) => $tq->where('title', 'like', '%'.$request->input('title').'%')))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => BlockResource::collection($paginator->items())->resolve(),
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

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'code'                     => ['required', 'string', Rule::in(BlockSettingsRegistry::codes())],
            'title'                    => ['nullable', 'string', 'max:255'],
            'description'              => ['nullable', 'string', 'max:2000'],
            'settings'                 => ['nullable', 'array'],
            'parent_block_index_ids'   => ['nullable', 'array'],
            'parent_block_index_ids.*' => [
                'integer',
                Rule::exists('block_indexes', 'id')->whereNull('deleted_at'),
            ],
            'published'                => ['sometimes', 'boolean'],
            'is_test' => ['sometimes', 'boolean'],
        ]);

        if (!empty($data['settings'])) {
            $data['settings'] = SettingsNormalizer::normalizeUrls($data['settings']);
        }

        $block = DB::transaction(function () use ($user, &$data) {
            $block = Block::create([
                'user_id'       => $user->id,
                'code'          => $data['code'],
                'settings'      => $data['settings'] ?? [],
                'published'     => $data['published'] ?? false,
                'is_test'       => $data['is_test'] ?? false,
            ]);

            $block->translations()->create([
                'locale'      => app()->getLocale(),
                'title'       => $data['title'] ?? '',
                'description' => $data['description'] ?? '',
            ]);

            if (!empty($data['settings'])) {
                SettingsNormalizer::syncFileSetsFromSettings($data['settings'], $block, $block->code);
                $block->update(['settings' => $data['settings']]);
            }

            // Привязка к узлам дерева: пустой/отсутствующий массив → без привязок
            $this->syncBlockIndexes($block, $data['parent_block_index_ids'] ?? []);

            return $block;
        });

        $block->load(['translations', 'blockIndexes']);

        return (new BlockResource($block))->response()->setStatusCode(201);
    }

    public function show(Request $request, Block $block): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($block->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $block->load(['translations', 'blockIndexes']);

        return (new BlockResource($block))->response();
    }

    public function update(Request $request, Block $block): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($block->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'title'                    => ['nullable', 'string', 'max:255'],
            'description'              => ['nullable', 'string', 'max:2000'],
            'settings'                 => ['nullable', 'array'],
            // sometimes: если клиент не прислал поле — не трогаем привязки
            'parent_block_index_ids'   => ['sometimes', 'nullable', 'array'],
            'parent_block_index_ids.*' => [
                'integer',
                Rule::exists('block_indexes', 'id')->whereNull('deleted_at'),
            ],
            'published'                => ['sometimes', 'boolean'],
            'is_test' => ['sometimes', 'boolean'],
        ]);

        if (!empty($data['settings'])) {
            $data['settings'] = SettingsNormalizer::normalizeUrls($data['settings']);
        }

        DB::transaction(function () use ($block, &$data) {
            if (!empty($data['settings'])) {
                SettingsNormalizer::syncFileSetsFromSettings($data['settings'], $block, $block->code);
            }

            $block->update([
                'settings' => $data['settings'] ?? $block->settings,
                'published' => $data['published'] ?? $block->published,
                'is_test'       => $data['is_test'] ?? false,
            ]);

            $locale = app()->getLocale();
            $translation = $block->translations()->firstOrNew(['locale' => $locale]);
            $translation->title       = $data['title'] ?? $translation->title ?? '';
            $translation->description = $data['description'] ?? $translation->description ?? '';
            $translation->save();

            // Привязка: null / [] → отвязать всё; массив → заменить состав
            if (array_key_exists('parent_block_index_ids', $data)) {
                $this->syncBlockIndexes($block, $data['parent_block_index_ids']);
            }
        });

        $block->load(['translations', 'blockIndexes']);

        return (new BlockResource($block))->response();
    }

    public function destroy(Request $request, Block $block): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($block->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $block->delete();

        return response()->json(['message' => 'Deleted'], 200);
    }

    /**
     * Синхронизация привязок блока к узлам дерева.
     */
    private function syncBlockIndexes(Block $block, ?array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids ?? [])));

        if ($ids === []) {
            $block->blockIndexes()->sync([]);
            return;
        }

        // Текущий порядок этого блока в каждом узле: [block_index_id => order]
        $existingOrders = DB::table('block_block_index')
            ->where('block_id', $block->id)
            ->pluck('order', 'block_index_id')
            ->all();

        $grammar = DB::connection()->getQueryGrammar();
        $orderColumn = $grammar->wrap('order');

        // Максимальный order по каждому целевому узлу (уже занятые позиции)
        $maxOrders = DB::table('block_block_index')
            ->selectRaw("block_index_id, MAX({$orderColumn}) as max_order")
            ->whereIn('block_index_id', $ids)
            ->groupBy('block_index_id')
            ->pluck('max_order', 'block_index_id')
            ->all();

        $sync = [];
        foreach ($ids as $bid) {
            $sync[$bid] = [
                'order' => $existingOrders[$bid] ?? ((int) ($maxOrders[$bid] ?? -1) + 1),
            ];
        }

        $block->blockIndexes()->sync($sync);
    }
}
