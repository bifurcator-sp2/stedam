<?php

declare(strict_types=1);

namespace App\Application\Lessons\Actions;

use App\Application\Lessons\DTOs\LessonTypeOption;
use App\Application\Lessons\Ports\LessonTypeQueryInterface;

/**
 * Справочник типов урока для выбора `type` в интерфейсе.
 *
 * Тонкий делегат в read-порт: HTTP-слой получает готовые DTO и не знает
 * ни о моделях, ни о таблице `lesson_types`.
 */
final readonly class ListLessonTypesAction
{
    public function __construct(
        private LessonTypeQueryInterface $lessonTypes,
    ) {}

    /**
     * @return list<LessonTypeOption>
     */
    public function __invoke(): array
    {
        return $this->lessonTypes->allOrdered();
    }
}
