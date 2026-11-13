<?php

declare(strict_types=1);

namespace App\Domain\Course\Entities;

use App\Domain\Course\Exceptions\InvalidCourseOrder;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\LessonTypeId;
use App\Domain\Course\ValueObjects\TimeGap;

/**
 * Урок внутри темы курса (аналог course_theme_lessons).
 *
 * Не путать с доменным Lesson из контекста Lessons: здесь только связь
 * (урок + тип + порядок + разрыв). Контент урока живёт в Lessons,
 * прохождение — в Learn.
 *
 * Иммутабельна на уровне агрегата: изменения проходят через методы Course
 * (после чего возвращается новый экземпляр).
 */
final readonly class CourseLesson
{
    public function __construct(
        private LessonId $lessonId,
        private int $order,
        private ?LessonTypeId $typeId,
        private TimeGap $timeGap,
    ) {
        if ($order <= 0) {
            throw InvalidCourseOrder::nonPositive($order);
        }
    }

    public static function create(
        LessonId $lessonId,
        int $order,
        ?LessonTypeId $typeId = null,
        ?TimeGap $timeGap = null,
    ): self {
        return new self(
            lessonId: $lessonId,
            order: $order,
            typeId: $typeId,
            timeGap: $timeGap ?? TimeGap::default(),
        );
    }

    public function lessonId(): LessonId
    {
        return $this->lessonId;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function typeId(): ?LessonTypeId
    {
        return $this->typeId;
    }

    public function timeGap(): TimeGap
    {
        return $this->timeGap;
    }

    public function withOrder(int $order): self
    {
        return new self($this->lessonId, $order, $this->typeId, $this->timeGap);
    }

    public function withType(?LessonTypeId $typeId): self
    {
        return new self($this->lessonId, $this->order, $typeId, $this->timeGap);
    }

    public function withTimeGap(TimeGap $timeGap): self
    {
        return new self($this->lessonId, $this->order, $this->typeId, $timeGap);
    }

    public function equals(self $other): bool
    {
        return $this->lessonId->equals($other->lessonId);
    }
}
