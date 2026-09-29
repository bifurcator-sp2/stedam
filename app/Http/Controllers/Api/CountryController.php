<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    /**
     * GET /api/countries
     * Справочник стран с названиями на текущей локали.
     *
     * Query-параметры:
     *   ?active=1     — только активные (по умолчанию true)
     *   ?search=рос   — поиск по названию (в текущей и fallback локалях)
     */
    public function index(Request $request): JsonResponse
    {
        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        $query = Country::query()
            ->with(['translations' => function ($q) use ($locale, $fallback) {
                $q->whereIn('locale', [$locale, $fallback]);
            }]);

        // По умолчанию отдаём только активные
        if ($request->boolean('active', true)) {
            $query->where('is_active', true);
        }

        // Поиск по названию
        if ($search = $request->string('search')->trim()->value()) {
            $query->whereHas('translations', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }
///**/
        $countries = $query
            ->orderBy('iso2')
            ->get()
            ->map(fn (Country $country) => [
                'id' => $country->id,
                'iso2' => $country->iso2,
                'iso3' => $country->iso3,
                'phone_code' => $country->phone_code,
                'name' => $country->name, // аксессор — текущая локаль с фолбэком
                'is_active' => $country->is_active,
            ])
            ->values();

        return response()->json($countries);
    }

    /**
     * GET /api/countries/{country}
     * Одна страна со всеми переводами.
     */
    public function show(Country $country): JsonResponse
    {
        $country->load('translations');

        return response()->json([
            'id' => $country->id,
            'iso2' => $country->iso2,
            'iso3' => $country->iso3,
            'phone_code' => $country->phone_code,
            'is_active' => $country->is_active,
            'name' => $country->name,
            'translations' => $country->translations->map(fn ($t) => [
                'locale' => $t->locale,
                'name' => $t->name,
            ])->values(),
        ]);
    }
}
