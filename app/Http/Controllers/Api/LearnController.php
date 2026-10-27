<?php


namespace App\Http\Controllers\Api;


use App\Http\Resources\LessonListResource;
use App\Models\Lesson;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearnController
{
    public function getLessonsListQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Lesson::query()
            ->withCount('blocks')
            ->with(['taxonomies:id']);

        return $query->orderByDesc('id');
    }

    public function list_learn(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $perPage = max(1, min((int)$request->integer('per_page', 20), 100));

        $paginator = $this->getLessonsListQuery($request)
            ->where('published', true)
            ->simplePaginate($perPage);

        return response()->json([
            'data' => LessonListResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'has_more_pages' => $paginator->hasMorePages(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function getActualLessons(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $lessons = $this->getLessonsListQuery($request)
            ->where('published', true)
            ->take(3)
            ->get();

        return response()->json(LessonListResource::collection($lessons)->resolve($request));
    }

}
