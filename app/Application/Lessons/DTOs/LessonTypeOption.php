<?php

declare(strict_types=1);

namespace App\Application\Lessons\DTOs;

/**
 * Тип урока из справочника — элемент списка для выбора в интерфейсе.
 *
 * `title`/`description` уже локализованы на момент сборки
 * (текущая локаль → `app.fallback_locale` → первая доступная).
 *
 * `nextLessonThreshold` — базовый порог допуска к следующему уроку, доля
 * `0..1` (`lesson_types.next_lesson_threshold`). Полезен фронту, чтобы
 * показать требование типа урока и предпросмотр порога курса
 * (`base(type) × threshold_multipler`, docs/course-api-spec.md §0.6).
 *
 * `timeGap` — дефолтный разрыв между уроками в целых часах
 * (`lesson_types.time_gap`): тип-зависимый дефолт для `course_theme_lessons.time_gap`.
 */
final readonly class LessonTypeOption
{
    public function __construct(
        public int $id,
        public string $code,
        public ?string $title,
        public ?string $description,
        public ?string $color,
        public int $sort,
        public float $nextLessonThreshold = 0.0,
        public int $timeGap = 12,
    ) {}
}
