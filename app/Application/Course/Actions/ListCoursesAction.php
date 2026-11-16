<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Context\CourseViewerRole;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\TaxonomyId;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\Paginator as ConcretePaginator;

/**
 * Листинг курсов для смотрящего (§13.2) — с постраничной выборкой.
 *
 *  - student — курсы, назначенные ему (системные — только если назначены);
 *  - parent — то же для его детей, без дублей;
 *  - admin/methodologist/headteacher — всё;
 *  - teacher — свои + системные;
 *  - guest — только системные.
 *
 * Фильтры taxId/search применяются в порту: таксономия — вместе с
 * поддеревом, поиск — без учёта регистра по title и description.
 *
 * Пагинация — та же, что у листинга уроков: без подсчёта total, страница
 * определяется наличием следующей строки. parent берёт страницу из порта по
 * каждому ребёнку и склеивает их без дублей: страница может оказаться
 * короче, но граница «есть ли ещё» не теряется, пока есть ребёнок с
 * hasMorePages().
 */
final readonly class ListCoursesAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
    ) {}

    /**
     * @return Paginator
     */
    public function execute(
        CourseViewerContext $viewer,
        ?TaxonomyId $taxId = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): Paginator {
        if ($viewer->isStudentView()) {
            $studentId = $viewer->userId();

            return $studentId === null
                ? new ConcretePaginator([], $perPage, $page)
                : $this->courses->listForStudent($studentId, $taxId, $search, $page, $perPage);
        }

        // parent: порт listForStudent принимает одного ученика, а у родителя
        // их несколько. Поэтому перебираем детей и склеиваем без дублей:
        // курс, назначенный двум детям, в списке родителя — один раз.
        if ($viewer->role() === CourseViewerRole::Parent) {
            return $this->listForParent($viewer, $taxId, $search, $page, $perPage);
        }

        // «Видит всё» (admin/methodologist/headteacher) — снимаем фильтр по
        // владельцу через allOwners: null в ownerId значит «только системные».
        if ($viewer->seesEverything()) {
            return $this->courses->listForOwner(
                ownerId: null,
                taxId: $taxId,
                search: $search,
                includeSystem: true,
                allOwners: true,
                page: $page,
                perPage: $perPage,
            );
        }

        // Teacher — свои + системные. Гость (userId = null) — только системные.
        return $this->courses->listForOwner(
            ownerId: $viewer->userId(),
            taxId: $taxId,
            search: $search,
            includeSystem: $viewer->seesSystemCourses(),
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * Страница каталога родителя: объединение страниц детей без дублей.
     *
     * @return Paginator
     */
    private function listForParent(
        CourseViewerContext $viewer,
        ?TaxonomyId $taxId,
        ?string $search,
        int $page,
        int $perPage,
    ): Paginator {
        $seen = [];
        $items = [];
        $hasMorePages = false;

        foreach ($viewer->childStudentIds() as $childId) {
            $childPage = $this->courses->listForStudent($childId, $taxId, $search, $page, $perPage);

            $hasMorePages = $hasMorePages || $childPage->hasMorePages();

            foreach ($childPage->items() as $course) {
                $id = $course->id()?->value;

                if ($id === null || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $items[] = $course;
            }
        }

        if (!$hasMorePages && count($items) > $perPage) {
            $hasMorePages = true;
            $items = array_slice($items, 0, $perPage);
        }

        return new ConcretePaginator($items, $perPage, $page);
    }
}
