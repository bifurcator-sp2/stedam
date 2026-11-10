<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\Learn\Exceptions\LessonNotFound;
use App\Application\Learn\Ports\LessonRepositoryInterface;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Http\Resources\LessonResource;
use App\Models\Lesson;

/**
 * Eloquent-реализация чтения уроков для контекста Learn.
 * Публичная проверка + сериализация для снапшота.
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

    public function getForSnapshot(LessonId $id): array
    {
        $lesson = Lesson::query()
            ->with(['blocks.block', 'lessonBlockAsTests.block', 'taxonomies'])
            ->find($id->value);

        if ($lesson === null) {
            throw new LessonNotFound("Lesson {$id->value} not found");
        }

        // Сериализуем так же, как отдавали в API ранее (LessonResource),
        // но без привязки к HTTP-запросу.
        return (new LessonResource($lesson))->resolve(request());
    }
}
