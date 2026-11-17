<?php

declare(strict_types=1);

namespace App\Domain\Course\Entities;

use App\Domain\Course\Exceptions\CourseLessonAlreadyInCourse;
use App\Domain\Course\Exceptions\CourseLessonNotInTheme;
use App\Domain\Course\Exceptions\InvalidCourseOrder;
use App\Domain\Course\ValueObjects\CourseThemeId;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\TimeGap;
use LogicException;

/**
 * Тема курса — упорядоченный набор уроков.
 *
 * Инварианты:
 *  - order темы и order урока — 1..N без дыр (нормализуется после мутации);
 *  - lesson_id внутри темы не дублируется;
 *  - тема без уроков допустима (создали заранее).
 */
final readonly class CourseTheme
{
    /**
     * @param list<CourseLesson> $lessons
     */
    public function __construct(
        private ?CourseThemeId $id,
        private string $title,
        private int $order,
        private array $lessons = [],
    ) {
        if ($order <= 0) {
            throw InvalidCourseOrder::nonPositive($order);
        }

        $this->assertNoDuplicates($lessons);
    }

    /**
     * Новая тема без id (id появится при сохранении агрегата).
     */
    public static function create(string $title, int $order): self
    {
        return new self(id: null, title: $title, order: $order, lessons: []);
    }

    /**
     * Гидрация из репозитория.
     *
     * @param list<CourseLesson> $lessons
     */
    public static function restore(
        CourseThemeId $id,
        string $title,
        int $order,
        array $lessons = [],
    ): self {
        return new self(id: $id, title: $title, order: $order, lessons: $lessons);
    }

    public function id(): ?CourseThemeId
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function order(): int
    {
        return $this->order;
    }

    /**
     * @return list<CourseLesson>
     */
    public function lessons(): array
    {
        return $this->lessons;
    }

    public function lessonsCount(): int
    {
        return count($this->lessons);
    }

    public function withId(CourseThemeId $id): self
    {
        return new self($id, $this->title, $this->order, $this->lessons);
    }

    public function rename(string $title): self
    {
        return new self($this->id, $title, $this->order, $this->lessons);
    }

    public function withOrder(int $order): self
    {
        return new self($this->id, $this->title, $order, $this->lessons);
    }

    /**
     * Заменить уроки темы целиком. Порядок — индекс в массиве (1-based).
     *
     * Уроки без time_gap получают default: расчёт по типу делает Application
     * до вызова (формула зависит от курса и справочника, домен их не знает).
     *
     * @param list<LessonId> $lessonIds
     * @param array<int, TimeGap> $timeGaps lessonId => разрыв
     */
    public function replaceLessons(array $lessonIds, array $timeGaps = []): self
    {
        $lessons = [];
        $position = 1;

        foreach ($lessonIds as $lessonId) {
            $lessons[] = CourseLesson::create(
                lessonId: $lessonId,
                order: $position++,
                timeGap: $timeGaps[$lessonId->value] ?? null,
            );
        }

        return new self($this->id, $this->title, $this->order, $this->renumber($lessons));
    }

    /**
     * Добавить урок в конец темы.
     *
     * time_gap по умолчанию — TimeGap::default(); расчёт по типу урока
     * выполняет Application-слой (домен справочника lesson_types не знает).
     */
    public function addLesson(LessonId $lessonId, ?TimeGap $timeGap = null): self
    {
        foreach ($this->lessons as $lesson) {
            if ($lesson->lessonId()->equals($lessonId)) {
                throw new CourseLessonAlreadyInCourse(
                    "Lesson {$lessonId->value} is already in theme " . ($this->id?->value ?? 0)
                );
            }
        }

        $lessons = $this->lessons;
        $lessons[] = CourseLesson::create(
            lessonId: $lessonId,
            order: count($lessons) + 1,
            timeGap: $timeGap,
        );

        return new self($this->id, $this->title, $this->order, $lessons);
    }

    public function removeLesson(LessonId $lessonId): self
    {
        $found = false;
        $lessons = [];

        foreach ($this->lessons as $lesson) {
            if ($lesson->lessonId()->equals($lessonId)) {
                $found = true;
                continue;
            }
            $lessons[] = $lesson;
        }

        if (!$found) {
            throw CourseLessonNotInTheme::withIds($this->id?->value ?? 0, $lessonId->value);
        }

        return new self($this->id, $this->title, $this->order, $this->renumber($lessons));
    }

    public function moveLesson(LessonId $lessonId, int $toPosition): self
    {
        $lessons = [];
        $moved = null;

        foreach ($this->lessons as $lesson) {
            if ($lesson->lessonId()->equals($lessonId)) {
                $moved = $lesson;
                continue;
            }
            $lessons[] = $lesson;
        }

        if ($moved === null) {
            throw CourseLessonNotInTheme::withIds($this->id?->value ?? 0, $lessonId->value);
        }

        if ($toPosition < 1 || $toPosition > count($lessons) + 1) {
            throw new InvalidCourseOrder(
                "Lesson position {$toPosition} is out of range"
            );
        }

        array_splice($lessons, $toPosition - 1, 0, [$moved]);

        return new self($this->id, $this->title, $this->order, $this->renumber($lessons));
    }

    /**
     * @param list<CourseLesson> $lessons
     * @return list<CourseLesson>
     */
    private function renumber(array $lessons): array
    {
        $result = [];
        $position = 1;

        foreach ($lessons as $lesson) {
            $result[] = $lesson->withOrder($position);
            $position++;
        }

        return $result;
    }

    /**
     * @param list<CourseLesson> $lessons
     */
    private function assertNoDuplicates(array $lessons): void
    {
        $seen = [];

        foreach ($lessons as $lesson) {
            $key = $lesson->lessonId()->value;

            if (isset($seen[$key])) {
                throw new CourseLessonAlreadyInCourse(
                    "Duplicate lesson {$key} in theme " . ($this->id?->value ?? 0)
                );
            }

            $seen[$key] = true;
        }
    }
}
