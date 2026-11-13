<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Course\Actions\AssignCourseAction;
use App\Application\Course\Actions\CreateCourseAction;
use App\Application\Course\Actions\DeleteCourseAction;
use App\Application\Course\Actions\EditCourseHeaderAction;
use App\Application\Course\Actions\EditCourseLessonAction;
use App\Application\Course\Actions\EditCourseThemeAction;
use App\Application\Course\Actions\ListCoursesAction;
use App\Application\Course\Actions\RecalculateCourseAction;
use App\Application\Course\Actions\RecalculateCourseForAllAction;
use App\Application\Course\Actions\UnassignCourseAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Context\CourseViewerRole;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Ports\CourseAssignmentRepositoryInterface;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseThemeId;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\LessonTypeId;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\TimeGap;
use App\Domain\Course\ValueObjects\UserId;
use App\Http\Resources\CourseAssignmentResource;
use App\Http\Resources\CourseResource;
use App\Infrastructure\Course\Context\EloquentCourseContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * HTTP-слой домена Courses. Контракт — docs/course-api-spec.md.
 *
 * Правило: контроллер только валидирует ввод, собирает VO и вызывает Action.
 * Никакой бизнес-логики — права проверяются внутри Actions, чтобы HTTP и
 * CLI (команды, очереди) подчинялись одному правилу.
 *
 * Замечание про видимость: Actions проверяют права на МУТАЦИЮ. Проверка
 * «видит ли курс» — забота этого слоя (CourseViewerContext::canView),
 * потому что без неё детальный GET отдал бы чужой курс любому учителю.
 */
final readonly class CoursesController
{
    public function __construct(
        private EloquentCourseContextResolver $contextResolver,
        private CourseRepositoryInterface $courses,
        private CourseAssignmentRepositoryInterface $assignments,
    ) {}

    // ------------------------------------------------------------------
    // Листинг и детальный курс
    // ------------------------------------------------------------------

    public function index(Request $request, ListCoursesAction $action): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'tax_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        $courses = $action->execute(
            viewer: $this->contextResolver->resolve(),
            taxId: isset($validated['tax_id']) ? new TaxonomyId((int) $validated['tax_id']) : null,
            search: $validated['search'] ?? null,
        );

        $total = count($courses);
        $slice = array_slice($courses, ($page - 1) * $perPage, $perPage);

        return CourseResource::collection(
            array_map(
                static fn (Course $course): CourseResource => new CourseResource($course, withThemes: false),
                $slice,
            ),
        )->additional([
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function show(int $courseId): JsonResponse
    {
        $viewer = $this->contextResolver->resolve();
        $course = $this->findCourseOrFail($courseId);

        $this->assertCanView($viewer, $course);

        return response()->json([
            ...(new CourseResource($course))->resolve(request()),
            'can' => $this->permissions($viewer, $course),
        ]);
    }

    // ------------------------------------------------------------------
    // Жизненный цикл курса
    // ------------------------------------------------------------------

    public function store(Request $request, CreateCourseAction $action): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['required', 'string', 'min:1', 'max:5000'],
            // Границы — как у VO Locale (xx или xx-XX, до 8 символов), иначе
            // пользователь получил бы 422 домена вместо ошибок валидации.
            'locale' => ['required', 'string', 'max:8', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
            'next_lesson_threshold' => ['required', 'numeric', 'gt:0', 'lte:1'],
            'tax_id' => ['nullable', 'integer'],
            // null = системный курс (только admin); отсутствие ключа = себе.
            'user_id' => ['nullable', 'integer'],
        ]);

        $viewer = $this->contextResolver->resolve();

        // Ключ есть и равен null → осознанный запрос системного курса.
        // Ключа нет → курс создаётся себе.
        $ownerId = $request->has('user_id') && $request->input('user_id') === null
            ? null
            : new UserId((int) ($validated['user_id'] ?? $viewer->userId()?->value ?? 0));

        $course = $action->execute(
            viewer: $viewer,
            ownerId: $ownerId,
            title: new CourseTitle($validated['title']),
            description: new CourseDescription($validated['description']),
            locale: new Locale($validated['locale']),
            threshold: new NextLessonThreshold((float) $validated['next_lesson_threshold']),
            taxId: isset($validated['tax_id']) ? new TaxonomyId((int) $validated['tax_id']) : null,
        );

        return response()->json(new CourseResource($course), 201);
    }

    public function update(int $courseId, Request $request, EditCourseHeaderAction $action): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'string', 'min:1', 'max:5000'],
            'locale' => ['sometimes', 'string', 'max:8', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
            'next_lesson_threshold' => ['sometimes', 'numeric', 'gt:0', 'lte:1'],
            'tax_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $course = $action->execute(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            title: isset($validated['title']) ? new CourseTitle($validated['title']) : null,
            description: isset($validated['description'])
                ? new CourseDescription($validated['description'])
                : null,
            locale: isset($validated['locale']) ? new Locale($validated['locale']) : null,
            threshold: isset($validated['next_lesson_threshold'])
                ? new NextLessonThreshold((float) $validated['next_lesson_threshold'])
                : null,
            taxId: isset($validated['tax_id']) ? new TaxonomyId((int) $validated['tax_id']) : null,
            // Передали ключ (даже null) — значит, хотим сменить таксономию.
            changeTaxonomy: $request->has('tax_id'),
        );

        return response()->json(new CourseResource($course));
    }

    public function destroy(int $courseId, DeleteCourseAction $action): JsonResponse
    {
        $action->execute(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
        );

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------------
    // Темы
    // ------------------------------------------------------------------

    public function storeTheme(
        int $courseId,
        Request $request,
        EditCourseThemeAction $action,
    ): JsonResponse {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $course = $action->add(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            title: $validated['title'],
        );

        return response()->json(new CourseResource($course), 201);
    }

    public function updateTheme(
        int $courseId,
        int $themeId,
        Request $request,
        EditCourseThemeAction $action,
    ): JsonResponse {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $course = $action->rename(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            themeId: new CourseThemeId($themeId),
            title: $validated['title'],
        );

        return response()->json(new CourseResource($course));
    }

    public function destroyTheme(
        int $courseId,
        int $themeId,
        EditCourseThemeAction $action,
    ): JsonResponse {
        $course = $action->remove(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            themeId: new CourseThemeId($themeId),
        );

        return response()->json(new CourseResource($course));
    }

    public function moveTheme(
        int $courseId,
        int $themeId,
        Request $request,
        EditCourseThemeAction $action,
    ): JsonResponse {
        $validated = $request->validate([
            'position' => ['required', 'integer', 'min:1'],
        ]);

        $course = $action->move(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            themeId: new CourseThemeId($themeId),
            toPosition: (int) $validated['position'],
        );

        return response()->json(new CourseResource($course));
    }

    // ------------------------------------------------------------------
    // Уроки в теме
    // ------------------------------------------------------------------

    public function storeLesson(
        int $courseId,
        int $themeId,
        Request $request,
        EditCourseLessonAction $action,
    ): JsonResponse {
        $validated = $request->validate([
            'lesson_id' => ['required', 'integer'],
            'type_id' => ['nullable', 'integer'],
            'time_gap' => ['nullable', 'integer', 'min:0'],
        ]);

        $course = $action->add(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            themeId: new CourseThemeId($themeId),
            lessonId: new LessonId((int) $validated['lesson_id']),
            typeId: isset($validated['type_id']) ? new LessonTypeId((int) $validated['type_id']) : null,
            timeGap: isset($validated['time_gap']) ? new TimeGap((int) $validated['time_gap']) : null,
        );

        return response()->json(new CourseResource($course), 201);
    }

    public function destroyLesson(
        int $courseId,
        int $themeId,
        int $lessonId,
        EditCourseLessonAction $action,
    ): JsonResponse {
        $course = $action->remove(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            themeId: new CourseThemeId($themeId),
            lessonId: new LessonId($lessonId),
        );

        return response()->json(new CourseResource($course));
    }

    public function moveLesson(
        int $courseId,
        int $themeId,
        int $lessonId,
        Request $request,
        EditCourseLessonAction $action,
    ): JsonResponse {
        $validated = $request->validate([
            'position' => ['required', 'integer', 'min:1'],
        ]);

        $course = $action->move(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            themeId: new CourseThemeId($themeId),
            lessonId: new LessonId($lessonId),
            toPosition: (int) $validated['position'],
        );

        return response()->json(new CourseResource($course));
    }

    public function updateLessonType(
        int $courseId,
        int $themeId,
        int $lessonId,
        Request $request,
        EditCourseLessonAction $action,
    ): JsonResponse {
        $validated = $request->validate([
            'type_id' => ['present', 'nullable', 'integer'],
        ]);

        $course = $action->changeType(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            themeId: new CourseThemeId($themeId),
            lessonId: new LessonId($lessonId),
            typeId: $validated['type_id'] === null
                ? null
                : new LessonTypeId((int) $validated['type_id']),
        );

        return response()->json(new CourseResource($course));
    }

    public function updateLessonTimeGap(
        int $courseId,
        int $themeId,
        int $lessonId,
        Request $request,
        EditCourseLessonAction $action,
    ): JsonResponse {
        $validated = $request->validate([
            'time_gap' => ['required', 'integer', 'min:0'],
        ]);

        $course = $action->changeTimeGap(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            themeId: new CourseThemeId($themeId),
            lessonId: new LessonId($lessonId),
            timeGap: new TimeGap((int) $validated['time_gap']),
        );

        return response()->json(new CourseResource($course));
    }

    // ------------------------------------------------------------------
    // Назначения
    // ------------------------------------------------------------------

    public function assignments(int $courseId): AnonymousResourceCollection
    {
        $viewer = $this->contextResolver->resolve();
        $course = $this->findCourseOrFail($courseId);
        // Прогресс учеников видят автор курса и роли с полным доступом.
        if (! $viewer->canEdit($course->userId()) && ! $viewer->seesEverything()) {
            throw CourseAccessDenied::edit($courseId);
        }

        return CourseAssignmentResource::collection(
            $this->assignments->listByCourse(new CourseId($courseId)),
        );
    }

    public function assign(int $courseId, Request $request, AssignCourseAction $action): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer'],
        ]);

        $assignment = $action->execute(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            studentId: new UserId((int) $validated['student_id']),
        );

        return response()->json(new CourseAssignmentResource($assignment), 201);
    }

    public function unassign(int $courseId, int $studentId, UnassignCourseAction $action): JsonResponse
    {
        $action->execute(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            studentId: new UserId($studentId),
        );

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------------
    // Пересчёт
    // ------------------------------------------------------------------

    public function recalculate(
        int $courseId,
        int $studentId,
        RecalculateCourseAction $action,
    ): JsonResponse {
        $assignment = $action->execute(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
            studentId: new UserId($studentId),
        );

        return response()->json(new CourseAssignmentResource($assignment));
    }

    /**
     * Пересчёт синхронный: к моменту ответа все назначения уже обновлены.
     * Поэтому 200 с числом обработанных, а не 202 «запланировано» —
     * иначе клиент не отличит поставленную в очередь задачу от выполненной.
     */
    public function recalculateAll(int $courseId, RecalculateCourseForAllAction $action): JsonResponse
    {
        $processed = $action->execute(
            viewer: $this->contextResolver->resolve(),
            courseId: new CourseId($courseId),
        );

        return response()->json(['processed' => $processed]);
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    private function findCourseOrFail(int $courseId): Course
    {
        return $this->courses->findById(new CourseId($courseId))
            ?? throw CourseNotFound::withId($courseId);
    }

    private function assertCanView(CourseViewerContext $viewer, Course $course): void
    {
        $courseId = $course->id() ?? throw CourseNotFound::withId(0);

        // Назначение проверяем ДО canView(): системный курс студент видит
        // только если он ему назначен (§13.2, вопрос №22 спеки).
        if ($this->isAssignedToScope($viewer, $courseId)) {
            if ($viewer->canView($course->userId(), isAssigned: true)) {
                return;
            }
        }

        if ($viewer->canView($course->userId())) {
            return;
        }

        throw CourseAccessDenied::view($courseId->value);
    }

    /**
     * Назначен ли курс смотрящему или наблюдаемому им ученику (родитель →
     * дети). Назначение — единственный источник доступа student/parent
     * к чужому курсу.
     */
    private function isAssignedToScope(CourseViewerContext $viewer, CourseId $courseId): bool
    {
        foreach ($viewer->studentScopeIds() as $studentId) {
            if ($this->assignments->exists($courseId, $studentId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Флаги для UI: чтобы фронт не дублировал матрицу прав (§1.4).
     *
     * @return array<string, bool>
     */
    private function permissions(CourseViewerContext $viewer, Course $course): array
    {
        $isAssigned = $course->id() !== null && $this->isAssignedToScope($viewer, $course->id());

        // Прогресс видит автор курса, роли с полным доступом и наблюдатель
        // (родитель — по назначению ребёнка, ученик — по своему).
        $seesProgress = $viewer->canEdit($course->userId())
            || $viewer->seesEverything()
            || ($isAssigned && ($viewer->isStudentView() || $viewer->role() === CourseViewerRole::Parent));

        return [
            'edit' => $viewer->canEdit($course->userId()),
            'delete' => $viewer->canEdit($course->userId()),
            // Назначенный системный курс ученику виден — но не «назначаем»:
            // canAssign() для student/parent и так false.
            'assign' => $viewer->canAssign() && $viewer->canView($course->userId(), isAssigned: $isAssigned),
            'see_progress' => $seesProgress,
        ];
    }
}
