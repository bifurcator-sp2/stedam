<?php

namespace App\Http\Controllers\Api;

use App\Blocks\Settings\BlockSettingsRegistry;
use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\BlockType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockTypeController extends Controller
{
    /**
     * GET /api/block-types
     *
     * Возвращает список типов блоков с переводами и настройками.
     * Доступ: только администратор.
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

        if ($request->boolean('active', true)) {
            // если у вас есть поле is_active — раскомментируйте
            // $query->where('is_active', true);
        }

        $items = $query->get()->map(function (BlockType $type) use ($locale, $fallback) {
            $translation = $type->translations->firstWhere('locale', $locale)
                ?? $type->translations->firstWhere('locale', $fallback)
                ?? $type->translations->first();

            return [
                'id' => $type->id,
                'code' => $type->code,
                'type' => $type->type, // info / task
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
     * Структура типов блоков с вложенными блоками текущего пользователя.
     * Используется для построения меню (например, в конструкторе уроков).
     * Без пагинации.
     * Доступ: только администратор.
     */
    public function struct(Request $request): JsonResponse
    {
        $user = $request->user();

  /*      if (! $user || ! $user->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }*/

        $locale   = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        // ---------- Типы ----------
        $types = BlockType::query()
            ->with(['translations' => function ($q) use ($locale, $fallback) {
                $q->whereIn('locale', [$locale, $fallback]);
            }])
            ->orderBy('code')
            ->get();

        if ($types->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $typeIds = $types->pluck('id')->all();

        // ---------- Блоки ----------
        $blocksByType = Block::query()
            ->with(['translations' => function ($q) use ($locale, $fallback) {
                $q->whereIn('locale', [$locale, $fallback]);
            }])
            ->where('user_id', $user->id)
            ->whereIn('block_type_id', $typeIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('block_type_id');

        // ---------- Сборка ----------
        $items = $types->map(function (BlockType $type) use ($locale, $fallback, $blocksByType) {
            $translation = $type->translations->firstWhere('locale', $locale)
                ?? $type->translations->firstWhere('locale', $fallback)
                ?? $type->translations->first();

            $blocks = $blocksByType->get($type->id, collect());

            return [
                'id'               => $type->id,
                'code'             => $type->code,
                'type'             => $type->type,
                'name'             => $translation?->name ?? $type->code,
                'description'      => $translation?->description,
                'default_settings' => $type->normalizedSettings,
                'blocks_count'     => $blocks->count(),
                'blocks'           => $blocks->map(function (Block $b) use ($locale, $fallback) {
                    $t = $b->translations->firstWhere('locale', $locale)
                        ?? $b->translations->firstWhere('locale', $fallback)
                        ?? $b->translations->first();

                    return [
                        'id'            => $b->id,
                        'block_type_id' => $b->block_type_id,
                        'title'         => $t?->title,
                        'description'   => $t?->description,
                        'settings'      => $b->settings ?? [],
                    ];
                })->values(),
            ];
        })->values();

        return response()->json(['data' => $items]);
    }


    /**
     * GET /api/block-types/{code}
     *
     * Один тип блока по code.
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        $type = BlockType::query()
            ->where('code', $code)
            ->with(['translations' => function ($q) use ($locale, $fallback) {
                $q->whereIn('locale', [$locale, $fallback]);
            }])
            ->first();

        if (! $type) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $translation = $type->translations->firstWhere('locale', $locale)
            ?? $type->translations->firstWhere('locale', $fallback)
            ?? $type->translations->first();

        return response()->json([
            'id' => $type->id,
            'code' => $type->code,
            'type' => $type->type,
            'name' => $translation?->name ?? $type->code,
            'description' => $translation?->description,
            'default_settings' => $type->default_settings ?? [],
            'translations' => $type->translations->map(fn ($t) => [
                'locale' => $t->locale,
                'name' => $t->name,
                'description' => $t->description,
            ])->values(),
            'created_at' => $type->created_at?->toISOString(),
            'updated_at' => $type->updated_at?->toISOString(),
        ]);
    }
}
