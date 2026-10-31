<?php


namespace App\Http\Controllers\Api;


use App\Http\Resources\LearnResource;
use App\Http\Resources\LessonListResource;
use App\Http\Resources\LessonResource;
use App\Models\Learn;
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
    /**
     * Начать прохождение урока.
     */
    public function beginLearnLesson(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // 1. Проверка роли (только студенты)
        if (!$user->hasRole('student')) {
            abort(403, 'Только студенты могут проходить уроки.');
        }

        // 2. Валидация ID урока
        $validated = $request->validate([
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
        ]);

        $lesson = Lesson::findOrFail($validated['lesson_id']);

        // 3. Проверка публикации
        if (!$lesson->published) {
            abort(403, 'Урок не опубликован.');
        }

        // 4. Проверка на наличие незавершенных прохождений
        $hasIncomplete = Learn::where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->whereNull('complete')
            ->exists();

        if ($hasIncomplete) {
            abort(422, 'У вас уже есть незавершенное прохождение этого урока.');
        }

        // 5. Загружаем все необходимые связи
        $lesson->load(['blocks.block', 'lessonBlockAsTests.block', 'taxonomies']);

        // 6. Создаем запись прохождения, сохраняя сериализованный ресурс урока
        $learn = Learn::create([
            'user_id'   => $user->id,
            'lesson_id' => $lesson->id,
            'lesson'    => (new LessonResource($lesson))->resolve($request),
            'score'     => 0,
        ]);

        // 7. Возвращаем чистый LearnResource
        return response()->json(new LearnResource($learn), 201);
    }
}
