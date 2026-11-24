<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\GradeSummary;
use App\Application\Learn\Ports\ActualLessonGradeQueryInterface;
use App\Application\Learn\Ports\ActualLessonScopeBuilderInterface;
use App\Application\Lessons\DTOs\LessonListFilter;
use App\Application\Lessons\Enums\ActualLessonsScope;
use App\Application\Lessons\Ports\LessonsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Learn\ValueObjects\UserId as LearnUserId;
use App\Domain\Lessons\ValueObjects\FullTextSearchQuery;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * «Актуальные уроки»: постраничный список опубликованных уроков.
 *
 * Отличие от каталога (ListLessonsAction):
 *   — не учитывается таксономия (tax) — только поиск;
 *   — по умолчанию сортировка по updated_at (недавно обновлённые — вверху);
 *   — параметр filter=unassigned|assigned|continue|grades сужает список
 *     по learns текущего пользователя и задаёт свою сортировку
 *     (см. ActualLessonsScope). Без filter поведение не меняется.
 *
 * Для смотрящего с ролью student в каждый урок подставляется
 * actual_lesson_scope — к какому срезу относится элемент списка
 * (см. LessonListResource). Для уроков со срезом grades дополнительно
 * подставляется grade — последняя оценка learns пользователя по уроку.
 * Для остальных ролей оба поля не вычисляются.
 */
final readonly class GetActualLessonsAction
{
    public function __construct(
        private LessonsQueryInterface $lessons,
        private ContextResolverInterface $contextResolver,
        private ActualLessonScopeBuilderInterface $scopeBuilder,
        private ActualLessonGradeQueryInterface $grades,
    ) {}

    /**
     * @param array<string, mixed> $input query-параметры запроса (search, filter)
     * @return PaginatedResult<\App\Models\Lesson>
     */
    public function paginate(array $input, int $page, int $perPage, int $viewerId): PaginatedResult
    {
        $scope = ActualLessonsScope::tryFrom((string) ($input['filter'] ?? ''));

        $result = $this->lessons->paginate(
            new LessonListFilter(
                search: self::buildSearch($input['search'] ?? null),
                onlyPublished: true,
                orderBy: 'updated_at',
                // Без filter — прежнее поведение: только поиск и updated_at.
                scope: $scope,
                viewerId: $scope !== null ? new UserId($viewerId) : null,
            ),
            $page,
            $perPage,
        );

        $this->attachActualScopes($result, $viewerId);

        return $result;
    }

    /**
     * Подмешивает к моделям уроков срез текущего пользователя и (для среза
     * grades) его оценку — только для смотрящего-ученика.
     */
    private function attachActualScopes(PaginatedResult $result, int $viewerId): void
    {
        if ($result->items === []) {
            return;
        }

        $context = $this->contextResolver->resolve(new LearnUserId($viewerId));
        if (!$context->isStudent()) {
            return;
        }

        $lessonIds = array_map(
            static fn ($lesson): int => (int) $lesson->id,
            $result->items,
        );

        $userId = new LearnUserId($viewerId);
        $scopes = $this->scopeBuilder->build($userId, $lessonIds);

        $gradeLessonIds = array_keys(array_filter(
            $scopes,
            static fn (ActualLessonsScope $scope): bool => $scope === ActualLessonsScope::Grades,
        ));

        $grades = $gradeLessonIds !== []
            ? $this->grades->latestByUser($userId, $gradeLessonIds)
            : [];

        foreach ($result->items as $lesson) {
            $lessonId = (int) $lesson->id;

            $scope = $scopes[$lessonId] ?? null;
            if ($scope !== null) {
                $lesson->setAttribute('actual_lesson_scope', $scope->value);
            }

            $grade = $grades[$lessonId] ?? null;
            if ($grade instanceof GradeSummary) {
                $lesson->setAttribute('actual_lesson_grade', $grade->toArray());
            }
        }
    }

    /**
     * Запрос без значимых символов («+++», «   ») — это «поиска нет»,
     * а не ошибка: список отдаётся без фильтра по тексту.
     */
    private static function buildSearch(mixed $raw): ?FullTextSearchQuery
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return FullTextSearchQuery::fromUserInput($raw);
        } catch (\DomainException) {
            return null;
        }
    }
}
