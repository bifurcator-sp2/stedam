<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Learn\Actions\CancelLearnAction;
use App\Application\Learn\Actions\GetAnswerUploadConfigAction;
use App\Application\Learn\Actions\GetLearnAction;
use App\Application\Learn\Actions\GradeLearnAction;
use App\Application\Learn\Actions\ListLearnsAction;
use App\Application\Learn\Actions\ListParentChildrenAction;
use App\Application\Learn\Actions\ListParentLearnsAction;
use App\Application\Learn\Actions\ListTeacherStudentsAction;
use App\Application\Learn\Actions\MarkDoneAction;
use App\Application\Learn\Actions\SaveAnswersAction;
use App\Application\Learn\Actions\ScheduleLearnAction;
use App\Application\Learn\Actions\StartLearnAction;
use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\GradeLearnData;
use App\Application\Learn\DTOs\LearnListFilter;
use App\Application\Learn\DTOs\MarkDoneData;
use App\Application\Learn\DTOs\SaveAnswersData;
use App\Application\Learn\DTOs\ScheduleLearnData;
use App\Application\Learn\DTOs\StartLearnData;
use App\Domain\Learn\Entities\Learn as LearnEntity;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\UserId;
use App\Http\Resources\LearnResource;
use App\Http\Resources\LessonListResource;
use App\Models\Learn as LearnModel;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LearnController
{
    /**
     * Каталог опубликованных уроков (для всех ролей).
     */
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
     * Начать прохождение урока (только student).
     */
    public function beginLearnLesson(
        Request $request,
        StartLearnAction $start,
        ContextResolverInterface $contextResolver,
    ): JsonResponse {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $context = $contextResolver->resolve(new UserId((int) $user->id));

        if (!$context->canStart) {
            abort(403, 'Только студенты могут проходить уроки.');
        }

        $validated = $request->validate([
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
        ]);

        $learn = $start(new StartLearnData(
            userId: new UserId((int) $user->id),
            lessonId: new LessonId((int) $validated['lesson_id']),
        ));

        return response()->json(
            new LearnResource($this->toModel($learn)),
            201,
        );
    }

    /**
     * Контракт загрузки изображений-ответов (для предвалидации на фронте).
     */
    public function answerUploadConfig(GetAnswerUploadConfigAction $action): JsonResponse
    {
        return response()->json($action());
    }

    /**
     * Автосохранение ответов (только student, своё, in_progress).
     */
    public function saveAnswers(Request $request, int $learn, SaveAnswersAction $action): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $answers = $this->parseRawAnswers($request, required: true);

        $entity = $action(new SaveAnswersData(
            learnId: new LearnId($learn),
            viewerId: new UserId((int) $user->id),
            answers: $answers,
        ));

        return response()->json(new LearnResource($this->toModel($entity)));
    }

    /**
     * Разбирает multipart-ответы: answers[i][index], answers[i][img].
     *
     * Файл-картинка (перо на планшете) + позиция блока.
     *
     * @return list<\App\Application\Learn\DTOs\RawAnswer>
     */
    private function parseRawAnswers(Request $request, bool $required): array
    {
        $maxKilobytes = intdiv((int) config('learn.answers.max_bytes', 10 * 1024 * 1024), 1024);

        $rules = [
            'answers' => [$required ? 'required' : 'sometimes', 'array'],
            'answers.*.index' => ['required', 'integer', 'min:1'],
            'answers.*.img' => [
                'required',
                'file',
                'max:'.$maxKilobytes,
                'mimetypes:'.implode(',', (array) config('learn.answers.allowed_mimes', ['image/png', 'image/jpeg', 'image/webp'])),
            ],
        ];

        $validated = $request->validate($rules);

        $raw = [];
        foreach ($validated['answers'] ?? [] as $entry) {
            if (!isset($entry['index'], $entry['img'])) {
                continue;
            }
            $raw[] = new \App\Application\Learn\DTOs\RawAnswer(
                (int) $entry['index'],
                $entry['img'],
            );
        }

        return $raw;
    }

    /**
     * Ученик завершает своё прохождение.
     */
    public function markDone(Request $request, int $learn, MarkDoneAction $action): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Финальные ответы передаются необязательно: если тело содержит images —
        // используем их (multipart), иначе берём уже автосохранённые.
        $hasAnswers = $request->has('answers');
        $answers = $hasAnswers ? $this->parseRawAnswers($request, required: false) : null;

        $entity = $action(new MarkDoneData(
            learnId: new LearnId($learn),
            viewerId: new UserId((int) $user->id),
            answers: $answers,
        ));

        return response()->json(new LearnResource($this->toModel($entity)));
    }

    /**
     * Учитель оценивает прохождение своего ученика.
     */
    public function grade(Request $request, int $learn, GradeLearnAction $action): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'score' => ['required', 'numeric', 'between:0,100'],
        ]);

        $entity = $action(new GradeLearnData(
            learnId: new LearnId($learn),
            viewerId: new UserId((int) $user->id),
            grade: new Grade((float) $validated['score']),
        ));

        return response()->json(new LearnResource($this->toModel($entity)));
    }

    /**
     * Учитель назначает/планирует урок ученику.
     *
     * body: { user_id, lesson_id, schedule_date? }
     */
    public function schedule(Request $request, ScheduleLearnAction $action): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'user_id'       => ['required', 'integer', 'exists:users,id'],
            'lesson_id'     => ['required', 'integer', 'exists:lessons,id'],
            'schedule_date' => ['nullable', 'date'],
        ]);

        $scheduleDate = isset($validated['schedule_date']) && $validated['schedule_date'] !== null
            ? new ScheduleDate(Carbon::parse($validated['schedule_date'])->toDateTimeImmutable())
            : null;

        $entity = $action(new ScheduleLearnData(
            viewerId: new UserId((int) $user->id),
            targetUserId: new UserId((int) $validated['user_id']),
            lessonId: new LessonId((int) $validated['lesson_id']),
            scheduleDate: $scheduleDate,
        ));

        return response()->json(new LearnResource($this->toModel($entity)), 201);
    }

    /**
     * Учитель отменяет запланированное прохождение (scheduled → created).
     */
    public function cancel(Request $request, int $learn, CancelLearnAction $action): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $entity = $action(new LearnId($learn), new UserId((int) $user->id));

        return response()->json(new LearnResource($this->toModel($entity)));
    }

    /**
     * Одно прохождение по id (в рамках контекста смотрящего).
     */
    public function show(Request $request, int $learn, GetLearnAction $action): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $entity = $action(new LearnId($learn), new UserId((int) $user->id));

        return response()->json(new LearnResource($this->toModel($entity)));
    }

    /**
     * Прохождения «моих» пользователей (учеников/детей) с фильтрами.
     *
     * GET /teacher/learns?status[]=done&lesson_id=1&date_from=...&date_to=...
     */
    public function teacherLearns(
        Request $request,
        ListLearnsAction $action,
        ContextResolverInterface $contextResolver,
    ): JsonResponse {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $viewerId = new UserId((int) $user->id);
        $context = $contextResolver->resolve($viewerId);

        $filter = $this->buildListFilter($request, $context);

        $page = $action($viewerId, $filter);

        return response()->json([
            'data' => array_map(
                fn (LearnEntity $e): array => (new LearnResource($this->toModel($e)))->resolve($request),
                $page->items,
            ),
            'meta' => [
                'current_page' => $page->page,
                'per_page'    => $page->perPage,
                'total'       => $page->total,
                'last_page'   => $page->lastPage(),
                'has_more_pages' => $page->hasMorePages(),
            ],
        ]);
    }

    /**
     * Список учеников учителя с агрегатами.
     *
     * GET /teacher/students?search=...&page=1&per_page=20
     */
    public function teacherStudents(
        Request $request,
        ListTeacherStudentsAction $action,
    ): JsonResponse {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $search = $request->query('search');
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $result = $action(
            new UserId((int) $user->id),
            is_string($search) ? $search : null,
            $page,
            $perPage,
        );

        return response()->json([
            'data' => array_map(
                static fn (\App\Application\Learn\DTOs\StudentSummary $s): array => [
                    'id'             => $s->id,
                    'name'           => $s->name,
                    'email'          => $s->email,
                    'learns_count'   => $s->learnsCount,
                    'in_progress'    => $s->inProgress,
                    'awaiting_grade' => $s->awaitingGrade,
                    'graded'         => $s->graded,
                    'avg_score'      => $s->avgScore,
                ],
                $result->items,
            ),
            'meta' => [
                'current_page'   => $result->page,
                'per_page'       => $result->perPage,
                'total'          => $result->total,
                'last_page'      => $result->lastPage(),
                'has_more_pages' => $result->hasMorePages(),
            ],
        ]);
    }

    /**
     * Прохождения детей родителя (read-only).
     *
     * GET /parent/learns?status[]=...&lesson_id=...&page=...&per_page=...
     */
    public function parentLearns(
        Request $request,
        ListParentLearnsAction $action,
        ContextResolverInterface $contextResolver,
    ): JsonResponse {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $viewerId = new UserId((int) $user->id);
        $context = $contextResolver->resolve($viewerId);

        $filter = $this->buildListFilter($request, $context);

        $page = $action($viewerId, $filter);

        return response()->json([
            'data' => array_map(
                fn (LearnEntity $e): array => (new LearnResource($this->toModel($e)))->resolve($request),
                $page->items,
            ),
            'meta' => [
                'current_page' => $page->page,
                'per_page'    => $page->perPage,
                'total'       => $page->total,
                'last_page'   => $page->lastPage(),
                'has_more_pages' => $page->hasMorePages(),
            ],
        ]);
    }

    /**
     * Список детей родителя с агрегатами.
     *
     * GET /parent/children?search=...&page=1&per_page=20
     */
    public function parentChildren(
        Request $request,
        ListParentChildrenAction $action,
    ): JsonResponse {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $search = $request->query('search');
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $result = $action(
            new UserId((int) $user->id),
            is_string($search) ? $search : null,
            $page,
            $perPage,
        );

        return response()->json([
            'data' => array_map(
                static fn (\App\Application\Learn\DTOs\StudentSummary $s): array => [
                    'id'             => $s->id,
                    'name'           => $s->name,
                    'email'          => $s->email,
                    'learns_count'   => $s->learnsCount,
                    'in_progress'    => $s->inProgress,
                    'awaiting_grade' => $s->awaitingGrade,
                    'graded'         => $s->graded,
                    'avg_score'      => $s->avgScore,
                ],
                $result->items,
            ),
            'meta' => [
                'current_page'   => $result->page,
                'per_page'       => $result->perPage,
                'total'          => $result->total,
                'last_page'      => $result->lastPage(),
                'has_more_pages' => $result->hasMorePages(),
            ],
        ]);
    }

    /**
     * Собирает LearnListFilter из query-параметров.
     */
    private function buildListFilter(Request $request, \App\Application\Learn\Context\ViewerContext $context): LearnListFilter
    {
        $statuses = [];
        foreach ((array) $request->input('status', []) as $raw) {
            $status = LearnStatus::tryFrom((string) $raw);
            if ($status !== null) {
                $statuses[] = $status;
            }
        }

        $lessonId = $request->integer('lesson_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        return new LearnListFilter(
            statuses: $statuses,
            lessonId: $lessonId > 0 ? new LessonId($lessonId) : null,
            dateFrom: $dateFrom !== null ? Carbon::parse($dateFrom)->toDateTimeImmutable() : null,
            dateTo: $dateTo !== null ? Carbon::parse($dateTo)->toDateTimeImmutable() : null,
            page: max(1, (int) $request->integer('page', 1)),
            perPage: max(1, min((int) $request->integer('per_page', 20), 100)),
            includeArchived: false,
        );
    }

    /**
     * Доменная сущность → тонкая Eloquent-модель для LearnResource.
     * Грузим из БД по id, чтобы ресурс получал актуальные timestamps/связи.
     */
    private function toModel(LearnEntity $entity): LearnModel
    {
        return LearnModel::query()->findOrFail($entity->id()->value);
    }
}
