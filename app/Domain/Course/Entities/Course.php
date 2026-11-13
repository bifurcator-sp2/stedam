<?php

declare(strict_types=1);

namespace App\Domain\Course\Entities;

use App\Domain\Course\Exceptions\CourseLessonAlreadyInCourse;
use App\Domain\Course\Exceptions\CourseLessonNotInTheme;
use App\Domain\Course\Exceptions\CourseLocaleChangeDenied;
use App\Domain\Course\Exceptions\CourseTaxonomyChangeDenied;
use App\Domain\Course\Exceptions\CourseThemeNotFound;
use App\Domain\Course\Exceptions\InvalidCourseOrder;
use App\Domain\Course\ValueObjects\Complexity;
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
use DateTimeImmutable;

/**
 * Агрегат Course — корень консистентности курсов.
 *
 * ЖИЗНЕННЫЙ ЦИКЛ (важно для состава):
 *  1. Создание — только шапка. `Course::create()` НЕ принимает темы: у нового
 *     курса нет id, а тема не может существовать без course_id (FK в БД).
 *     Создание курса = шапка + save(), темы = отдельный шаг редактирования.
 *  2. Редактирование — только через `restore()` загруженного курса с id.
 *     Каждая тема уже имеет id, поэтому `addLesson()` находит её по
 *     CourseThemeId и работает.
 *
 * Инварианты:
 *  - порядок тем и уроков в теме: 1..N без дыр, уникален;
 *  - lesson_id уникален в пределах ВСЕГО курса (не только темы): один урок
 *    не может вистеть в двух темах;
 *  - смена locale и tax_id разрешена только пока в курсе нет уроков;
 *  - user_id === null — системный курс.
 *
 * Иммутабельна: каждая мутация возвращает новый экземпляр.
 */
final class Course
{
    /**
     * @param list<CourseTheme> $themes
     */
    private function __construct(
        private ?CourseId $id,
        private ?UserId $userId,
        private CourseTitle $title,
        private CourseDescription $description,
        private Locale $locale,
        private NextLessonThreshold $nextLessonThreshold,
        private ?TaxonomyId $taxId,
        private array $themes,
        private ?DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Создание курса — только шапка, без тем.
     *
     * Темы добавляются на уже сохранённом курсе (`restore($saved)->addTheme()`).
     * Причина: тема не существует без course_id (FK course_themes.course_id).
     */
    public static function create(
        ?UserId $userId,
        CourseTitle $title,
        CourseDescription $description,
        Locale $locale,
        NextLessonThreshold $nextLessonThreshold,
        ?TaxonomyId $taxId = null,
    ): self {
        return new self(
            id: null,
            userId: $userId,
            title: $title,
            description: $description,
            locale: $locale,
            nextLessonThreshold: $nextLessonThreshold,
            taxId: $taxId,
            themes: [],
            updatedAt: null,
        );
    }

    /**
     * Восстановление из репозитория (гидрация). Все темы обязаны иметь id.
     *
     * @param list<CourseTheme> $themes
     */
    public static function restore(
        CourseId $id,
        ?UserId $userId,
        CourseTitle $title,
        CourseDescription $description,
        Locale $locale,
        NextLessonThreshold $nextLessonThreshold,
        ?TaxonomyId $taxId,
        array $themes,
        ?DateTimeImmutable $updatedAt,
    ): self {
        foreach ($themes as $theme) {
            if ($theme->id() === null) {
                throw new \LogicException(
                    'Restored Course themes must have id (course must be saved first)'
                );
            }
        }

        return new self(
            id: $id,
            userId: $userId,
            title: $title,
            description: $description,
            locale: $locale,
            nextLessonThreshold: $nextLessonThreshold,
            taxId: $taxId,
            themes: self::renumberThemes($themes),
            updatedAt: $updatedAt,
        );
    }

    public function id(): ?CourseId
    {
        return $this->id;
    }

    public function userId(): ?UserId
    {
        return $this->userId;
    }

    public function isSystem(): bool
    {
        return $this->userId === null;
    }

    public function title(): CourseTitle
    {
        return $this->title;
    }

    public function description(): CourseDescription
    {
        return $this->description;
    }

    public function locale(): Locale
    {
        return $this->locale;
    }

    public function nextLessonThreshold(): NextLessonThreshold
    {
        return $this->nextLessonThreshold;
    }

    public function taxId(): ?TaxonomyId
    {
        return $this->taxId;
    }

    /**
     * @return list<CourseTheme>
     */
    public function themes(): array
    {
        return $this->themes;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function withId(CourseId $id): self
    {
        return new self(
            $id,
            $this->userId,
            $this->title,
            $this->description,
            $this->locale,
            $this->nextLessonThreshold,
            $this->taxId,
            $this->themes,
            $this->updatedAt,
        );
    }

    public function touch(DateTimeImmutable $now): self
    {
        return $this->copy(updatedAt: $now);
    }

    // === Шапка ===

    public function rename(CourseTitle $title): self
    {
        return $this->copy(title: $title);
    }

    public function changeDescription(CourseDescription $description): self
    {
        return $this->copy(description: $description);
    }

    public function changeNextLessonThreshold(NextLessonThreshold $threshold): self
    {
        return $this->copy(nextLessonThreshold: $threshold);
    }

    /**
     * Сменить язык курса можно, пока в курсе нет уроков.
     */
    public function changeLocale(Locale $locale): self
    {
        if ($locale->equals($this->locale)) {
            return $this;
        }

        $lessonCount = $this->countLessons();

        if ($lessonCount > 0) {
            throw CourseLocaleChangeDenied::hasLessons($lessonCount);
        }

        return $this->copy(locale: $locale);
    }

    /**
     * Перенести курс в другую ветку таксономии можно, пока в курсе нет уроков.
     */
    public function moveToTaxonomy(?TaxonomyId $taxId): self
    {
        if ($taxId?->value === $this->taxId?->value) {
            return $this;
        }

        $lessonCount = $this->countLessons();

        if ($lessonCount > 0) {
            throw CourseTaxonomyChangeDenied::hasLessons($lessonCount);
        }

        return $this->copy(taxId: $taxId, taxIdIsSet: true);
    }

    // === Темы ===

    /**
     * Добавить тему. Титул темы задаётся строкой: отдельный VO не нужен,
     * length-контроль уже сделал CourseTitle на шапке, а тема — простая
     * надпись (≤255 в БД).
     */
    public function addTheme(string $title): self
    {
        $this->assertPersisted();

        $themes = $this->themes;
        $themes[] = CourseTheme::create($title, count($themes) + 1);

        return $this->copy(themes: self::renumberThemes($themes));
    }

    public function renameTheme(CourseThemeId $themeId, string $title): self
    {
        $this->requireTheme($themeId);

        return $this->copy(themes: $this->mapThemes(
            fn (CourseTheme $theme) => $theme->id()?->equals($themeId) === true
                ? $theme->rename($title)
                : $theme,
        ));
    }

    public function removeTheme(CourseThemeId $themeId): self
    {
        $this->requireTheme($themeId);

        $themes = array_values(array_filter(
            $this->themes,
            fn (CourseTheme $theme) => $theme->id()?->equals($themeId) !== true,
        ));

        return $this->copy(themes: self::renumberThemes($themes));
    }

    public function moveTheme(CourseThemeId $themeId, int $toPosition): self
    {
        $this->requireTheme($themeId);

        if ($toPosition < 1 || $toPosition > count($this->themes)) {
            throw InvalidCourseOrder::nonPositive($toPosition);
        }

        $moving = null;
        $rest = [];

        foreach ($this->themes as $theme) {
            if ($theme->id()?->equals($themeId) === true) {
                $moving = $theme;
                continue;
            }
            $rest[] = $theme;
        }

        array_splice($rest, $toPosition - 1, 0, [$moving]);

        return $this->copy(themes: self::renumberThemes($rest));
    }

    // === Уроки в теме ===

    public function addLesson(
        CourseThemeId $themeId,
        LessonId $lessonId,
        ?LessonTypeId $typeId = null,
        ?TimeGap $timeGap = null,
    ): self {
        $this->requireTheme($themeId);
        $this->assertLessonNotUsed($lessonId);

        return $this->copy(themes: $this->mapThemes(
            fn (CourseTheme $theme) => $theme->id()?->equals($themeId) === true
                ? $theme->addLesson($lessonId, $typeId, $timeGap)
                : $theme,
        ));
    }

    public function removeLesson(CourseThemeId $themeId, LessonId $lessonId): self
    {
        $this->requireTheme($themeId);

        return $this->copy(themes: $this->mapThemes(
            fn (CourseTheme $theme) => $theme->id()?->equals($themeId) === true
                ? $theme->removeLesson($lessonId)
                : $theme,
        ));
    }

    public function moveLesson(CourseThemeId $themeId, LessonId $lessonId, int $toPosition): self
    {
        $this->requireTheme($themeId);

        return $this->copy(themes: $this->mapThemes(
            fn (CourseTheme $theme) => $theme->id()?->equals($themeId) === true
                ? $theme->moveLesson($lessonId, $toPosition)
                : $theme,
        ));
    }

    public function changeLessonType(
        CourseThemeId $themeId,
        LessonId $lessonId,
        ?LessonTypeId $typeId,
    ): self {
        $this->requireTheme($themeId);

        return $this->copy(themes: $this->mapThemes(
            fn (CourseTheme $theme) => $theme->id()?->equals($themeId) === true
                ? $theme->changeLessonType($lessonId, $typeId)
                : $theme,
        ));
    }

    public function changeLessonTimeGap(
        CourseThemeId $themeId,
        LessonId $lessonId,
        TimeGap $timeGap,
    ): self {
        $this->requireTheme($themeId);

        return $this->copy(themes: $this->mapThemes(
            fn (CourseTheme $theme) => $theme->id()?->equals($themeId) === true
                ? $theme->changeLessonTimeGap($lessonId, $timeGap)
                : $theme,
        ));
    }

    // === Производные ===

    public function countLessons(): int
    {
        $count = 0;

        foreach ($this->themes as $theme) {
            $count += $theme->lessonsCount();
        }

        return $count;
    }

    /**
     * Сложность курса — среднее сложностей уроков.
     *
     * @param iterable<?float> $lessonComplexities
     */
    public function deriveComplexity(iterable $lessonComplexities): ?Complexity
    {
        return Complexity::averageOf($lessonComplexities);
    }

    /**
     * Плоский порядок: (themeOrder, lessonOrder) → урок.
     *
     * @return list<array{theme: CourseTheme, lesson: CourseLesson}>
     */
    public function flatLessons(): array
    {
        $flat = [];

        $themes = $this->themes;
        usort($themes, fn (CourseTheme $a, CourseTheme $b) => $a->order() <=> $b->order());

        foreach ($themes as $theme) {
            $lessons = $theme->lessons();
            usort($lessons, fn (CourseLesson $a, CourseLesson $b) => $a->order() <=> $b->order());

            foreach ($lessons as $lesson) {
                $flat[] = ['theme' => $theme, 'lesson' => $lesson];
            }
        }

        return $flat;
    }

    /**
     * @return array{theme: CourseTheme, lesson: CourseLesson}|null
     */
    public function findLesson(LessonId $lessonId): ?array
    {
        foreach ($this->flatLessons() as $entry) {
            if ($entry['lesson']->lessonId()->equals($lessonId)) {
                return $entry;
            }
        }

        return null;
    }

    // === Внутренние помощники ===

    /**
     * Мутации состава требуют сохранённого курса: иначе нет id, к которому
     * привязать тему.
     */
    private function assertPersisted(): void
    {
        if ($this->id === null) {
            throw new \LogicException(
                'Course must be saved before adding themes or lessons'
            );
        }
    }

    private function requireTheme(CourseThemeId $themeId): void
    {
        $this->assertPersisted();

        foreach ($this->themes as $theme) {
            if ($theme->id()?->equals($themeId) === true) {
                return;
            }
        }

        throw CourseThemeNotFound::withId($themeId->value);
    }

    private function assertLessonNotUsed(LessonId $lessonId): void
    {
        if ($this->findLesson($lessonId) !== null) {
            throw CourseLessonAlreadyInCourse::forCourse(
                $this->id->value,
                $lessonId->value,
            );
        }
    }

    /**
     * @param callable(CourseTheme): CourseTheme $fn
     * @return list<CourseTheme>
     */
    private function mapThemes(callable $fn): array
    {
        return array_map($fn, $this->themes);
    }

    /**
     * @param list<CourseTheme> $themes
     * @return list<CourseTheme>
     */
    private static function renumberThemes(array $themes): array
    {
        $result = [];
        $position = 1;

        foreach ($themes as $theme) {
            $result[] = $theme->withOrder($position);
            $position++;
        }

        return $result;
    }

    /**
     * @param list<CourseTheme>|null $themes
     */
    private function copy(
        ?CourseTitle $title = null,
        ?CourseDescription $description = null,
        ?Locale $locale = null,
        ?NextLessonThreshold $nextLessonThreshold = null,
        ?TaxonomyId $taxId = null,
        bool $taxIdIsSet = false,
        ?array $themes = null,
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        return new self(
            $this->id,
            $this->userId,
            $title ?? $this->title,
            $description ?? $this->description,
            $locale ?? $this->locale,
            $nextLessonThreshold ?? $this->nextLessonThreshold,
            $taxIdIsSet ? $taxId : $this->taxId,
            $themes ?? $this->themes,
            $updatedAt ?? $this->updatedAt,
        );
    }
}
