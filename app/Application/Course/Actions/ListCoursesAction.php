<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\TaxonomyId;

/**
 * Листинг курсов для смотрящего (§13.2).
 *
 *  - student — курсы, назначенные ему;
 *  - admin/methodologist/headteacher — всё;
 *  - teacher — свои + системные;
 *  - guest — только системные.
 */
final readonly class ListCoursesAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
    ) {}

    /**
     * @return list<Course>
     */
    public function execute(
        CourseViewerContext $viewer,
        ?TaxonomyId $taxId = null,
        ?string $search = null,
    ): array {
        if ($viewer->isStudentView()) {
            $studentId = $viewer->userId();

            return $studentId === null ? [] : $this->courses->listForStudent($studentId);
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
            );
        }

        // Teacher — свои + системные. Гость (userId = null) — только системные.
        return $this->courses->listForOwner(
            ownerId: $viewer->userId(),
            taxId: $taxId,
            search: $search,
            includeSystem: $viewer->seesSystemCourses(),
        );
    }
}
