<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Course\Ports\LessonReaderInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseLesson;
use App\Domain\Course\Entities\CourseTheme;
use App\Domain\Course\Exceptions\CourseTaxonomyChangeDenied;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TaxonomyId;
use DateTimeImmutable;

/**
 * Редактирование шапки курса.
 *
 * Смена locale доменно запрещена, если в курсе есть уроки
 * (CourseLocaleChangeDenied).
 *
 * Смена tax_id проверяется в два уровня:
 *  - домен: у непустого курса смена запрещена (CourseTaxonomyChangeDenied);
 *  - Application: даже у пустого курса проверяем, что все уроки с уже
 *    заданной веткой лежат внутри целевого поддерева. Иначе курс окажется
 *    в ветке, к которой его уроки не относятся.
 *
 * CourseChanged НЕ публикуется: шапка не меняет состав курса, а значит
 * completion (доля пройденных уроков) и grade (среднее по урокам) остаются
 * прежними. Порог допуска влияет на то, кого пустят дальше, но не на уже
 * посчитанные оценки — пересчитывать нечего.
 *
 * Параметры необязательные: null = «не менять».
 */
final readonly class EditCourseHeaderAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
        private LessonReaderInterface $lessons,
    ) {}

    public function execute(
        CourseViewerContext $viewer,
        CourseId $courseId,
        ?CourseTitle $title = null,
        ?CourseDescription $description = null,
        ?Locale $locale = null,
        ?NextLessonThreshold $threshold = null,
        ?TaxonomyId $taxId = null,
        bool $changeTaxonomy = false,
        ?DateTimeImmutable $now = null,
    ): Course {
        $course = $this->courses->findById($courseId)
            ?? throw CourseNotFound::withId($courseId->value);

        if (!$viewer->canEdit($course->userId())) {
            throw CourseAccessDenied::edit($courseId->value);
        }

        if ($title !== null) {
            $course = $course->rename($title);
        }

        if ($description !== null) {
            $course = $course->changeDescription($description);
        }

        if ($locale !== null) {
            $course = $course->changeLocale($locale);
        }

        if ($threshold !== null) {
            $course = $course->changeNextLessonThreshold($threshold);
        }

        if ($changeTaxonomy) {
            $course = $this->moveWithTaxonomyCheck($course, $taxId);
        }

        return $this->courses->save($course->touch($now ?? new DateTimeImmutable()));
    }

    /**
     * Перенос курса в другую ветку таксономии с проверкой уроков.
     *
     * Домен разрешает смену только у пустого курса (CourseTaxonomyChangeDenied),
     * но «пустой» — не единственное условие: даже если уроков нет, курс с темой
     * без уроков формально непустой. Логика двухступенчатая:
     *
     *  1. Домен: countLessons() > 0 → сразу отказ (быстрая проверка).
     *  2. Если уроки есть и target — не их ветка → отказ (семантика
     *     «курс и уроки в одной ветке»).
     *
     * Уроки «без ветки» не блокируют смену: их можно донести позже.
     */
    private function moveWithTaxonomyCheck(Course $course, ?TaxonomyId $taxId): Course
    {
        $lessons = $course->flatLessons();

        if ($lessons === []) {
            return $course->moveToTaxonomy($taxId);
        }

        if ($taxId === null) {
            // Сброс «в никуда» — домен сам решит по правилам (сейчас — откажет).
            return $course->moveToTaxonomy($taxId);
        }

        $this->assertLessonsWithinTaxonomy($lessons, $taxId);

        return $course->moveToTaxonomy($taxId);
    }

    /**
     * Все уроки с заданной веткой должны попадать в целевое поддерево.
     *
     * @param list<array{theme: CourseTheme, lesson: CourseLesson}> $lessons
     */
    private function assertLessonsWithinTaxonomy(array $lessons, TaxonomyId $target): void
    {
        $subtree = $this->lessons->taxonomySubtreeIds($target->value);

        $lessonIds = [];
        foreach ($lessons as $entry) {
            $lessonIds[] = $entry['lesson']->lessonId();
        }

        $taxonomyByLesson = $this->lessons->taxonomyIdsOf($lessonIds);

        $foreign = [];

        foreach ($lessonIds as $lessonId) {
            $lessonTaxId = $taxonomyByLesson[$lessonId->value] ?? null;

            // Урок без ветки — не препятствие.
            if ($lessonTaxId === null) {
                continue;
            }

            if (!in_array($lessonTaxId, $subtree, true)) {
                $foreign[] = $lessonId->value;
            }
        }

        if ($foreign !== []) {
            throw CourseTaxonomyChangeDenied::lessonsOutsideTarget($target->value, $foreign);
        }
    }
}
