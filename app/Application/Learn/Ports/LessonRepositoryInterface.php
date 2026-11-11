<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\ValueObjects\LessonId;

/**
 * Доступ Learn-контекста к урокам.
 *
 * Learn знает об уроке только то, что нужно для старта: существует ли он
 * и опубликован ли. Всё, что касается содержания (для снапшота/генерации),
 * живёт за специализированными портами:
 *   - LessonSnapshotAssemblerInterface — сборка структуры для хэша;
 *   - LearnQuestionsBuilderInterface   — генерация задач.
 */
interface LessonRepositoryInterface
{
    public function isPublished(LessonId $id): bool;
}
