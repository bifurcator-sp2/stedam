<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Создание курса — ТОЛЬКО шапка.
 *
 * Темы и уроки добавляются на уже сохранённом курсе через
 * CourseEditingActions: у новой темы нет id, пока курс не сохранён.
 *
 * Права (§13.1):
 *  - обычный курс: создавать можно ТОЛЬКО себе (ownerId === viewerId);
 *  - системный курс (ownerId = null): только admin. Методист системные
 *    курсы не создаёт; редактировать существующий системный он может.
 *
 * Редактирование (в отличие от создания) всегда идёт по владению —
 * «вижу все курсы» не даёт прав на правку (см. CourseViewerContext::canEdit).
 */
final readonly class CreateCourseAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
    ) {}

    public function execute(
        CourseViewerContext $viewer,
        ?UserId $ownerId,
        CourseTitle $title,
        CourseDescription $description,
        Locale $locale,
        NextLessonThreshold $threshold,
        ?TaxonomyId $taxId = null,
    ): Course {
        if (!$viewer->canCreate($ownerId)) {
            throw CourseAccessDenied::edit(0);
        }

        return $this->courses->save(
            Course::create(
                userId: $ownerId,
                title: $title,
                description: $description,
                locale: $locale,
                nextLessonThreshold: $threshold,
                taxId: $taxId,
            ),
        );
    }
}
