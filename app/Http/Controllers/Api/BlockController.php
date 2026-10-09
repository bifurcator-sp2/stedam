<?php

namespace App\Http\Controllers\Api;

use App\Enums\FilePurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\BlockResource;
use App\Models\Block;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BlockController extends Controller
{
    /**
     * GET /api/blocks?page=1&per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $paginator = Block::query()
            ->with(['blockType.translations', 'translations'])
            ->where('user_id', $user->id)
            ->when($request->filled('block_type_id'),
                fn ($q) => $q->where('block_type_id', (int) $request->input('block_type_id')))
            ->when($request->filled('code'),
                fn ($q) => $q->whereHas('blockType',
                    fn ($bq) => $bq->where('code', $request->input('code'))))
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

    /**
     * POST /api/blocks
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'block_type_id' => [
                'required',
                'integer',
                Rule::exists('block_types', 'id'),
            ],
            'title'       => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'settings'    => ['nullable', 'array'],
            'files'       => ['nullable', 'array'],
            'images'      => ['nullable', 'array'],
        ]);

        $block = DB::transaction(function () use ($user, $data) {
            $block = Block::create([
                'user_id'       => $user->id,
                'block_type_id' => $data['block_type_id'],
                'settings'      => $data['settings'] ?? [],
            ]);

            $block->translations()->create([
                'locale'      => app()->getLocale(),
                'title'       => $data['title'] ?? '',
                'description' => $data['description'] ?? '',
            ]);

            return $block;
        });

        // Синхронизируем файлы, если фронт их прислал
        if (array_key_exists('files', $data) || array_key_exists('images', $data)) {
            $block->syncFilesFromRequest(
                $data['images'] ?? null,
                $data['files']  ?? null,
            );
        }

        // Синхронизация FileSet по settings
        if (!empty($data['settings'])) {
            $this->syncFileSets($block, $data['settings']);
        }

        $block->load(['blockType.translations', 'translations']);

        return (new BlockResource($block))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/blocks/{block}
     */
    public function show(Request $request, Block $block): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($block->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $block->load(['blockType.translations', 'translations']);

        return (new BlockResource($block))->response();
    }

    /**
     * PUT /api/blocks/{block}
     */
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
            'title'       => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'settings'    => ['nullable', 'array'],
            'files'       => ['nullable', 'array'],
            'images'      => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($block, $data) {
            $block->update([
                'settings' => $data['settings'] ?? $block->settings,
            ]);

            $locale = app()->getLocale();

            $translation = $block->translations()->firstOrNew(['locale' => $locale]);
            $translation->title       = $data['title'] ?? $translation->title ?? '';
            $translation->description = $data['description'] ?? $translation->description ?? '';
            $translation->save();
        });

        if (array_key_exists('files', $data) || array_key_exists('images', $data)) {
            $block->syncFilesFromRequest(
                $data['images'] ?? null,
                $data['files']  ?? null,
            );
        } else {
            // Совместимость: если фронт не прислал списки — просто переносим temp.
            $block->moveTempToStore();
        }

        // Синхронизация FileSet по settings
        if (!empty($data['settings'])) {
            $this->syncFileSets($block, $data['settings']);
        }

        $block->load(['blockType.translations', 'translations']);

        return (new BlockResource($block))->response();
    }

    /**
     * DELETE /api/blocks/{block}
     */
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

    /* ============================================================
     *  Синхронизация FileSet по settings
     * ============================================================ */

    /**
     * Проходит по всем разрешённым purpose блока и синхронизирует FileSet
     * согласно settings.
     *
     * Для каждого purpose:
     *  - ищет в settings узел с type="image" и allowed[0] === purpose;
     *  - если найден — берёт node.default (массив images) и вызывает
     *    fileSet(purpose)->syncFilesFromRequest(images, null).
     *
     * @param Block $block
     * @param array $settings — дерево SettingNode[]
     */
    protected function syncFileSets(Block $block, array $settings): void
    {
        foreach ($block::filePurposes() as $purposeEnum) {
            $purpose = $purposeEnum instanceof FilePurpose
                ? $purposeEnum->value
                : $purposeEnum;

            // Ищем узел с type=image и allowed[0] == purpose
            $node = $this->findSettingNode(
                $settings,
                fn ($n) => ($n['type'] ?? null) === 'image'
                    && (($n['allowed'][0] ?? null) === $purpose),
            );

            if ($node === null) {
                continue;
            }

            $images = is_array($node['default'] ?? null)
                ? $node['default']
                : [];

            $fileSet = $block->fileSet($purpose);

            $fileSet->syncFilesFromRequest($images, null);
        }
    }

    /**
     * Рекурсивный поиск узла в дереве settings.
     *
     * @param array $nodes
     * @param callable $predicate
     * @return array|null
     */
    protected function findSettingNode(array $nodes, callable $predicate): ?array
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if ($predicate($node)) {
                return $node;
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $found = $this->findSettingNode($node['children'], $predicate);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
