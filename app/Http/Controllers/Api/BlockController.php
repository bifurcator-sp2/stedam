<?php
// app/Http/Controllers/Api/BlockController.php

namespace App\Http\Controllers\Api;

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

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'block_type_id' => ['required', 'integer', Rule::exists('block_types', 'id')],
            'title'         => ['nullable', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'settings'      => ['nullable', 'array'],
        ]);

        if (!empty($data['settings'])) {
            $data['settings'] = SettingsNormalizer::normalizeUrls($data['settings']);
        }

        // Передаем $data по ссылке, чтобы syncFileSetsFromSettings обновил пути картинок
        $block = DB::transaction(function () use ($user, &$data) {
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

            // Синхронизация и замена temp URL на store URL
            if (!empty($data['settings'])) {
                $block->load('blockType');
                $block->syncFileSetsFromSettings($data['settings'], $block->blockType->code);
                $block->update(['settings' => $data['settings']]);
            }

            return $block;
        });

        $block->load(['blockType.translations', 'translations']);

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

        $block->load(['blockType.translations', 'translations']);

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
            'title'       => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'settings'    => ['nullable', 'array'],
        ]);

        if (!empty($data['settings'])) {
            $data['settings'] = SettingsNormalizer::normalizeUrls($data['settings']);
        }

        DB::transaction(function () use ($block, &$data) {
            // Синхронизация (мутирует $data['settings'])
            if (!empty($data['settings'])) {
                $block->load('blockType');
                $block->syncFileSetsFromSettings($data['settings'], $block->blockType->code);
            }

            // Единое сохранение
            $block->update([
                'settings' => $data['settings'] ?? $block->settings,
            ]);

            $locale = app()->getLocale();
            $translation = $block->translations()->firstOrNew(['locale' => $locale]);
            $translation->title       = $data['title'] ?? $translation->title ?? '';
            $translation->description = $data['description'] ?? $translation->description ?? '';
            $translation->save();
        });

        $block->load(['blockType.translations', 'translations']);

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
}
