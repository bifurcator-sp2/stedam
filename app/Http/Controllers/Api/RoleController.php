<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{

    public static function getPublicRoles(){
        $names = self::normalizeNames(self::getPublicRolesNames());

        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        // Подгружаем переводы заранее, чтобы избежать N+1
        return Role::query()
            ->whereIn('name', $names)
            ->with(['translations' => function ($q) use ($locale, $fallback) {
                $q->whereIn('locale', [$locale, $fallback]);
            }])
            ->get()
            // Сохраняем порядок, как в запросе names
            ->sortBy(fn (Role $role) => array_search($role->name, $names, true))
            ->values()
            ->map(function (Role $role) use ($locale, $fallback) {
                $translation = self::resolveTranslation($role, $locale, $fallback);

                return [
                    'name' => $role->name,
                    'label' => $translation?->label,
                    'description' => $translation?->description,
                ];
            });
    }

    /**
     * GET /api/roles?names[]=admin&names[]=editor
     *
     * Возвращает роли по списку name с label и description на текущей локали.
     * Формат ответа: [{ name, label, description }, ...]
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(self::getPublicRoles());
    }

    /**
     * Приводит входной параметр к чистому массиву строк.
     * Поддерживает: names[]=admin, names=admin,editor, names=admin
     */
    private static function normalizeNames(mixed $names): array
    {
        if (is_string($names)) {
            $names = array_map('trim', explode(',', $names));
        }

        if (! is_array($names)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($n) => is_string($n) ? trim($n) : null,
            $names
        )));
    }

    /**
     * Ищет перевод для текущей локали, при отсутствии — для fallback.
     */
    private static function resolveTranslation(Role $role, string $locale, string $fallback='ru'): ?object
    {
        return $role->translations->firstWhere('locale', $locale)
            ?? $role->translations->firstWhere('locale', $fallback);
    }

    public static function getPublicRolesNames(): array
    {
        return config('app.public_roles', []);
    }

}
