<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlockResource;
use App\Models\Block;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->with(['blockType.translations'])
            ->where('user_id', $user->id)
            ->when($request->filled('block_type_id'),
                fn ($q) => $q->where('block_type_id', (int) $request->input('block_type_id')))
            ->when($request->filled('code'),
                fn ($q) => $q->whereHas('blockType',
                    fn ($bq) => $bq->where('code', $request->input('code'))))
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
}
