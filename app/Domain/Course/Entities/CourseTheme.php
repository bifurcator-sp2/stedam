<?php

declare(strict_types=1);

namespace App\Domain\Course\Entities;

use App\Domain\Course\Exceptions\CourseLessonAlreadyInCourse;
use App\Domain\Course\Exceptions\CourseLessonNotInTheme;
use App\Domain\Course\Exceptions\InvalidCourseOrder;
use App\Domain\Course\ValueObjects\CourseThemeId;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\LessonTypeId;
use App\Domain\Course\ValueObjects\TimeGap;
use LogicException;

/**
 * Тема курса — упорядоченный набор уроков.
 *
 * Инварианты:
 *  - order темы и order урока — 1..N без дыр (нормализуется после мутации);
 *  - lesson_id внутри темы не дублируется;
 *  - тема без уроков допустима (создали заранее);
 *  - тема с уроками не может жить без id (нужен FK theme_id).
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

        if ($id === null && $lessons !== []) {
            throw new LogicException(
                'CourseTheme without id cannot contain lessons: theme must be saved first'
            );
        }

        $this->assertNoDuplicates($lessons);
    }

    /**
     * Новая тема. Всегда без уроков: у неё ещё нет id, уроки добавятся
     * после сохранения темы.
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

    public function addLesson(
        LessonId $lessonId,
        ?LessonTypeId $typeId = null,
        ?TimeGap $timeGap = null,
    ): self {
        if ($this->id === null) {
            throw new LogicException(
                'Cannot add lesson to unsaved theme: save the theme first'
            );
        }

        foreach ($this->lessons as $lesson) {
            if ($lesson->lessonId()->equals($lessonId)) {
                throw new CourseLessonAlreadyInCourse(
                    "Theme {$this->id->value} already contains lesson {$lessonId->value}"
                );
            }
        }

        $lessons = $this->lessons;
        $lessons[] = CourseLesson::create(
            lessonId: $lessonId,
            order: count($lessons) + 1,
            typeId: $typeId,
            timeGap: $timeGap,
        );

        return new self($this->id, $this->title, $this->order, $this->renumber($lessons));
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

    public function changeLessonType(LessonId $lessonId, ?LessonTypeId $typeId): self
    {
        return $this->mutateLesson(
            $lessonId,
            fn (CourseLesson $lesson) => $lesson->withType($typeId),
        );
    }

    public function changeLessonTimeGap(LessonId $lessonId, TimeGap $timeGap): self
    {
        return $this->mutateLesson(
            $lessonId,
            fn (CourseLesson $lesson) => $lesson->withTimeGap($timeGap),
        );
    }

    /**
     * @param callable(CourseLesson): CourseLesson $fn
     */
    private function mutateLesson(LessonId $lessonId, callable $fn): self
    {
        $lessons = [];
        $found = false;

        foreach ($this->lessons as $lesson) {
            if ($lesson->lessonId()->equals($lessonId)) {
                $lessons[] = $fn($lesson);
                $found = true;
                continue;
            }
            $lessons[] = $lesson;
        }

        if (!$found) {
            throw CourseLessonNotInTheme::withIds($this->id?->value ?? 0, $lessonId->value);
        }

        return new self($this->id, $this->title, $this->order, $lessons);
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
