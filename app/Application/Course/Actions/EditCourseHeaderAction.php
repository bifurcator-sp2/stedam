<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Course\Ports\LessonReaderInterface;
use App\Application\Course\Services\LessonTimeGapResolver;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseLesson;
use App\Domain\Course\Entities\CourseTheme;
use App\Domain\Course\Events\CourseChanged;
use App\Domain\Course\Exceptions\CourseTaxonomyChangeDenied;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseThemeId;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\ThresholdMultiplier;
use App\Domain\Course\ValueObjects\TimeGapMultiplier;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Редактирование шапки курса и его состава.
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
 * СОСТАВ (`themes`): ключа нет — трогаем только шапку; ключ есть — полная
 * замена тем и уроков, порядок = индекс в массиве. Пустой массив — «тем нет»,
 * то есть удаление всего состава. Так фронт сохраняет курс одним запросом
 * (черновик редактора), а не серией атомарных мутаций.
 *
 * time_gap урока — производная (тип урока × time_gap_multipler курса),
 * считается LessonTimeGapResolver'ом и пересчитывается при каждом изменении
 * множителя, даже если состав в запросе не пришёл.
 *
 * CourseChanged публикуется только когда состав изменился: шапка не меняет
 * completion/grade назначений, пересчитывать нечего.
 *
 * Параметры необязательные: null = «не менять».
 *
 * Публикация (published) — как у урока: опубликованный курс можно изменить
 * только сняв публикацию (`published = false`), при этом прочие поля запроса
 * игнорируются (фронт вправе отправить объект курса целиком). Любая другая
 * правка опубликованного курса → CourseIsPublishedEdit → HTTP 422.
 * Правка шапки и публикация в одном запросе допустимы: поля применяются до
 * `setPublished(true)`. Проверки живут в агрегате Course, здесь — только
 * порядок применения.
 */
final readonly class EditCourseHeaderAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
        private LessonReaderInterface $lessons,
        private LessonTimeGapResolver $timeGaps,
        private Dispatcher $events,
    ) {}

    public function execute(
        CourseViewerContext $viewer,
        CourseId $courseId,
        ?CourseTitle $title = null,
        ?CourseDescription $description = null,
        ?Locale $locale = null,
        ?ThresholdMultiplier $thresholdMultiplier = null,
        ?TimeGapMultiplier $timeGapMultiplier = null,
        ?TaxonomyId $taxId = null,
        bool $changeTaxonomy = false,
        ?DateTimeImmutable $now = null,
        ?bool $published = null,
        ?array $themes = null,
    ): Course {
        $course = $this->courses->findById($courseId)
            ?? throw CourseNotFound::withId($courseId->value);

        if (!$viewer->canEdit($course->userId())) {
            throw CourseAccessDenied::edit($courseId->value);
        }

        // Снятие публикации — атомарное действие: опубликованный курс сначала
        // снимают с публикации, и только следующим запросом меняют содержимое.
        if ($course->isPublished() && $published === false) {
            return $this->courses->save($course->setPublished(false)->touch($now ?? new DateTimeImmutable()));
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

        if ($thresholdMultiplier !== null) {
            $course = $course->changeThresholdMultiplier($thresholdMultiplier);
        }

        if ($timeGapMultiplier !== null) {
            $course = $course->changeTimeGapMultiplier($timeGapMultiplier);
        }

        if ($changeTaxonomy) {
            $course = $this->moveWithTaxonomyCheck($course, $taxId);
        }

        // Состав и паузы. Порядок важен: replaceThemes оставляет уроки без
        // time_gap, затем общий пересчёт проставляет их по множителю — уже
        // итоговому (он мог прийти в этом же запросе).
        if ($themes !== null) {
            $course = $this->replaceThemes($course, $themes);
        }

        // Пересчёт при любом изменении множителя: иначе сохранённый
        // time_gap_multipler не влиял бы на паузы до следующей правки состава.
        if ($timeGapMultiplier !== null) {
            $lessonIds = $this->lessonIdsOf($course);
            $gaps = $this->timeGaps->resolveMany($lessonIds, $course->timeGapMultiplier());

            $course = $course->recalculateTimeGaps($gaps);
        }

        // Публикация — последним шагом: правки шапки относятся к состоянию
        // «до публикации», поэтому публикация с правкой в одном запросе
        // допустима. Обратное неверно: у уже опубликованного курса правки
        // выше упрутся в CourseIsPublishedEdit.
        if ($published !== null) {
            $course = $course->setPublished($published);
        }

        $saved = $this->courses->save($course->touch($now ?? new DateTimeImmutable()));

        if ($themes !== null) {
            // Состав изменился: completion/grade назначений посчитаны по
            // прежнему набору уроков.
            $this->events->dispatch(new CourseChanged($courseId));
        }

        return $saved;
    }

    /**
     * Полная замена состава: порядок тем и уроков — индекс в массиве.
     *
     * Пустой массив — «в курсе нет тем»: все темы и связи удаляются.
     * Отсутствие ключа обрабатывает вызывающий (шапка без состава).
     *
     * @param list<array{id: int|null, title: string, lessons: list<int>}> $themes
     */
    private function replaceThemes(Course $course, array $themes): Course
    {
        $definitions = [];
        $themeIds = [];
        $lessonIds = [];

        foreach ($themes as $theme) {
            $themeIds[] = $theme['id'] === null ? null : new CourseThemeId((int) $theme['id']);

            $ids = [];
            foreach ($theme['lessons'] as $lessonId) {
                $ids[] = new LessonId((int) $lessonId);
                $lessonIds[] = new LessonId((int) $lessonId);
            }

            $definitions[] = [
                'title' => (string) $theme['title'],
                'lessonIds' => $ids,
                'timeGaps' => [],
            ];
        }

        $newCourse = $course->replaceThemes($definitions, $themeIds);

        // Новые и переставленные уроки получают паузу по типу и множителю.
        $gaps = $this->timeGaps->resolveMany(
            $lessonIds,
            $newCourse->timeGapMultiplier(),
            $this->fallbackGaps($themes),
        );

        return $newCourse->recalculateTimeGaps($gaps);
    }

    /**
     * Часы, пришедшие от клиента (если он их прислал) — на случай, когда
     * тип урока неизвестен справочнику.
     *
     * @param list<array{id: int|null, title: string, lessons: list<int>}> $themes
     * @return array<int, int>
     */
    private function fallbackGaps(array $themes): array
    {
        $fallbacks = [];

        foreach ($themes as $theme) {
            foreach ($theme['lessons'] as $lessonId) {
                $hours = $theme['time_gaps'][(int) $lessonId] ?? null;

                if ($hours !== null) {
                    $fallbacks[(int) $lessonId] = (int) $hours;
                }
            }
        }

        return $fallbacks;
    }
    /**
     * @return list<LessonId>
     */
    private function lessonIdsOf(Course $course): array
    {
        $ids = [];

        foreach ($course->flatLessons() as $entry) {
            $ids[] = $entry['lesson']->lessonId();
        }

        return $ids;
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
