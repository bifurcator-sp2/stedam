<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\Learn\Ports\LessonRepositoryInterface;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Models\Lesson as LessonModel;

/**
 * Eloquent-реализация узкого Learn-порта LessonRepositoryInterface.
 *
 * Learn-контексту нужен ровно один факт об уроке — опубликован ли он.
 * Запрос идёт по первичному ключу, без eager-load; мягко удалённые
 * (soft-deleted) уроки считаются неопубликованными — модели Lesson
 * использует SoftDeletes, и стандартный scope их отфильтрует.
 *
 * ВАЖНО: это НЕ полный репозиторий урока. Полный агрегат (создание,
 * обновление, таксономии, блоки) живёт в EloquentLessonAggregateRepository
 * (порт App\Application\Lessons\Ports\LessonRepositoryInterface).
 */
final class EloquentLessonRepository implements LessonRepositoryInterface
{
    public function isPublished(LessonId $id): bool
    {
        return (bool) LessonModel::query()
            ->whereKey($id->value)
            ->value('published');
    }
}
