<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserDataController extends Controller
{
    /**
     * GET /api/user-data
     * Просмотр профиля. Доступ: владелец или admin.
     */
    public function show(Request $request): JsonResponse
    {
        $userData = $this->resolveUserData($request);
        $this->authorizeView($request, $userData);

        return response()->json($this->transform($userData));
    }

    /**
     * POST /api/user-data
     * Создание своего профиля. Доступ: только владелец.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->userData) {
            return response()->json([
                'message' => 'Профиль уже существует. Используйте PUT/PATCH для обновления.',
            ], 409);
        }

        $data = $this->validateData($request);
        $userData = $user->userData()->create($data);
        $userData->load('country.translations');

        return response()->json($this->transform($userData), 201);
    }

    /**
     * PUT /api/user-data
     * Обновление. Доступ: владелец или admin.
     * Если профиля нет — создаём (для владельца).
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $userData = $user->userData;

        if (! $userData) {
            $data = $this->validateData($request);
            $userData = $user->userData()->create($data);
            $userData->load('country.translations');

            return response()->json($this->transform($userData), 201);
        }

        $this->authorizeView($request, $userData);

        $data = $this->validateData($request);
        $userData->update($data);
        $userData->load('country.translations');

        return response()->json($this->transform($userData->fresh()));
    }

    /**
     * DELETE /api/user-data
     * Удаление. Доступ: владелец или admin.
     */
    public function destroy(Request $request): JsonResponse
    {
        $userData = $this->resolveUserData($request);
        $this->authorizeView($request, $userData);

        $userData->delete();

        return response()->json(null, 204);
    }

    /**
     * Профиль текущего пользователя.
     */
    private function resolveUserData(Request $request): UserData
    {
        $userData = $request->user()->userData()
            ->with(['country.translations'])
            ->first();

        if (! $userData) {
            abort(404, 'Профиль не найден');
        }

        return $userData;
    }

    /**
     * Владелец или admin.
     */
    private function authorizeView(Request $request, UserData $userData): void
    {
        $user = $request->user();

        $allowed = $user->id === $userData->user_id
            || $user->hasRole('admin');

        if (! $allowed) {
            abort(403, 'Forbidden');
        }
    }

    /**
     * Валидация строго под структуру таблицы user_data.
     */
    private function validateData(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            // string('first_name')->nullable()
            'first_name' => ['nullable', 'string', 'max:255'],

            // string('last_name')->nullable()
            'last_name' => ['nullable', 'string', 'max:255'],

            // string('middle_name')->nullable()
            'middle_name' => ['nullable', 'string', 'max:255'],

            // unsignedSmallInteger('birth_year')->nullable()
            // unsignedSmallInteger max = 65535, но по логике ограничим разумным диапазоном
            'birth_year' => ['nullable', 'integer', 'min:1900', 'max:' . date('Y')],

            // enum('gender', ['male', 'female'])->nullable()
            'gender' => ['nullable', Rule::in(['male', 'female'])],

            // foreignId('country_id')->nullable()->constrained('countries')
            'country_id' => [
                'nullable',
                'integer',
                Rule::exists('countries', 'id')->whereNull('deleted_at'),
            ],
            'direction' => ['nullable', Rule::in(['ltr', 'rtl'])],
        ]);

        /*if ($validator->fails()) {
            abort(422, $validator->errors()->first());
        }*/
        return $validator->validate();
        //return $validator->validated();
    }

    /**
     * Формат ответа строго под поля таблицы.
     */
    private function transform(UserData $userData): array
    {
        return [
            'id' => $userData->id,
            'user_id' => $userData->user_id,
            'first_name' => $userData->first_name,
            'last_name' => $userData->last_name,
            'middle_name' => $userData->middle_name,
            'full_name' => $userData->full_name,
            'birth_year' => $userData->birth_year,
            'age' => $userData->age,
            'gender' => $userData->gender,
            'country_id' => $userData->country_id,
            'direction' => $userData->direction,
            'country' => $userData->country ? [
                'id' => $userData->country->id,
                'iso2' => $userData->country->iso2,
                'iso3' => $userData->country->iso3,
                'name' => $userData->country->name,
            ] : null,
            'created_at' => $userData->created_at?->toISOString(),
            'updated_at' => $userData->updated_at?->toISOString(),
        ];
    }
}
