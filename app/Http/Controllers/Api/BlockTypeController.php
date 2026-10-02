<?php

namespace App\Http\Controllers\Api;

use App\Blocks\Settings\BlockSettingsRegistry;
use App\Http\Controllers\Controller;
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
