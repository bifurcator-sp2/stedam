<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): Response
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->string('password')),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return response()->noContent();
    }

    public function setPublicRoles(Request $request): JsonResponse
    {
        /**
         * POST /api/set-user-roles
         *
         * Управляет ТОЛЬКО публичными ролями.
         * Непубличные роли (admin и т.п.) сохраняются без изменений.
         */

        $publicRoles = RoleController::getPublicRolesNames();

        if (empty($publicRoles)) {
            abort(500, 'Публичные роли не настроены');
        }

        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1', 'max:' . count($publicRoles)],
            'roles.*' => ['string', Rule::in($publicRoles)],
        ]);

        $user = $request->user();

        // 1. Текущие роли пользователя
        $currentRoles = $user->getRoleNames()->all();

        // 2. Непубличные роли — оставляем как есть
        $preservedRoles = array_values(array_diff($currentRoles, $publicRoles));

        // 3. Итоговый набор: непубличные + присланные публичные
        $finalRoles = array_values(array_unique(
            array_merge($preservedRoles, $validated['roles'])
        ));

        $user->syncRoles($finalRoles);

        return response()->json(['roles'=>$finalRoles]);

    }

}
