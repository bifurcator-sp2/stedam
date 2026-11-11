<?php

declare(strict_types=1);

namespace App\Application\Lessons\DTOs;

/**
 * Метаданные нового урока.
 *
 * $locale === null — «взять локаль владельца или app.locale».
 * Выбор конкретной строки — за Application-слоем (через хелпер),
 * домен получает уже готовую локаль.
 */
final readonly class CreateLessonData
{
    public function __construct(
        public ?string $locale = null,
    ) {}
}
