<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Community\Actions\GetRelationsTypesAction;
use App\Application\Community\Actions\ListCommunityUsersAction;
use App\Application\Community\DTOs\RelationTypeSummary;
use App\Domain\Community\ValueObjects\UserId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP-слой домена Community.
 *
 * Правило как в CoursesController: контроллер только собирает VO из
 * аутентифицированного пользователя и вызывает Action; бизнес-логики нет.
 */
final readonly class CommunityController
{
    /**
     * GET /api/my-relations
     *
     * Список типов связей текущего пользователя с количеством
     * действующих связей по каждому.
     */
    public function myRelations(Request $request, GetRelationsTypesAction $action): JsonResponse
    {
        $summaries = $action->execute(
            new UserId((int) $request->user()->id),
        );

        return response()->json([
            'data' => array_map(
                static fn (RelationTypeSummary $summary): array => $summary->toArray(),
                $summaries,
            ),
        ]);
    }

    /**
     * GET /api/users?search=&roles[]=&relation=&counterpart_role=&direction=&page=&per_page=
     *
     * Список пользователей. relation и counterpart_role работают парой —
     * например его ученики: `?relation=teacher&counterpart_role=student`.
     * roles[] требует наличия ВСЕХ перечисленных ролей и комбинируется со
     * связью: `?relation=teacher&counterpart_role=student&roles[]=parent` —
     * мои ученики, которые при этом являются родителями.
     */
    public function users(Request $request, ListCommunityUsersAction $action): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min($request->integer('per_page', 20), 100));

        $result = $action->paginate(
            new UserId((int) $request->user()->id),
            $request->query(),
            $page,
            $perPage,
        );

        $items = array_map(
            static fn ($user): array => $user->toArray(),
            $result->items,
        );

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $result->page,
                'per_page' => $result->perPage,
                'has_more_pages' => $result->hasMorePages(),
                'from' => $items === [] ? null : ($result->page - 1) * $result->perPage + 1,
                'to' => $items === [] ? null : ($result->page - 1) * $result->perPage + count($items),
            ],
        ]);
    }
}
