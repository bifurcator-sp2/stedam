<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\Learn\Ports\LessonRepositoryInterface;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Models\Lesson;

/**
 * Eloquent-реализация доступа Learn-контекста к урокам.
 *
 * Отвечает ТОЛЬКО на вопрос «опубликован ли урок». Содержимое урока для
 * снапшота собирает Infrastructure\Learn\EloquentLessonSnapshotAssembler,
 * задачи генерирует Infrastructure\Learn\EloquentLearnQuestionsBuilder.
 */
final class EloquentLessonRepository implements LessonRepositoryInterface
{
    public function isPublished(LessonId $id): bool
    {
        return Lesson::query()
            ->whereKey($id->value)
            ->where('published', true)
            ->exists();
    }
}
