<?php

declare(strict_types=1);

namespace App\Application\Lessons\Actions;

use App\Application\Lessons\Ports\LessonRepositoryInterface;
use App\Domain\Lessons\ValueObjects\LessonId;

/**
 * Удалить урок по id.
 *
 * Публичного HTTP-эндпоинта удаления урока пока нет, но сеялки
 * (SeedLessonsDomain / SeedLotrLessons) удаляют уроки перед reseed —
 * теперь через домен, а не сырым SQL. Отсутствие урока — не ошибка
 * (idempotent delete).
 *
 * Чистка собственных блоков и pivot-привязок — забота репозитория
 * (см. EloquentLessonAggregateRepository::delete + model-события).
 */
final readonly class DeleteLessonAction
{
    public function __construct(
        private LessonRepositoryInterface $lessons,
    ) {}

    public function __invoke(LessonId $id): void
    {
        $this->lessons->delete($id);
    }
}
