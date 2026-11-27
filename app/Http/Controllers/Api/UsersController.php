<?php

namespace App\Http\Controllers\Api;

use App\Application\Users\Actions\ListUsersAction;
use App\Http\Resources\UserListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsersController
{
    /**
     * Список пользователей: поиск + фильтр по ролям + пагинация.
     *
     * GET /users?search=<...>&roles[]=teacher&roles[]=student&page=1&per_page=20
     *
     *   — search: сначала пробуем привести к int — при успехе поиск по users.id;
     *     иначе ищем вхождения (от 3 символов) по users.email и
     *     user_data.first_name/last_name/middle_name;
     *   — roles: пользователь обязан иметь ВСЕ указанные роли.
     */
    public function list(Request $request, ListUsersAction $listUsers): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $result = $listUsers->paginate($request->query(), $page, $perPage);

        return response()->json([
            'data' => UserListResource::collection(collect($result->items))->resolve($request),
            'meta' => [
                'current_page'   => $result->page,
                'per_page'       => $result->perPage,
                'has_more_pages' => $result->hasMorePages(),
                'from'           => $result->items === [] ? null : ($result->page - 1) * $result->perPage + 1,
                'to'             => $result->items === [] ? null : ($result->page - 1) * $result->perPage + count($result->items),
            ],
            // Справочник ролей с локализованными названиями — один на весь ответ.
            'roles' => RoleController::getPublicRoles(),
        ]);
    }
}
