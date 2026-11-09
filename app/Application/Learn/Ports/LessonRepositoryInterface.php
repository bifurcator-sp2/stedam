<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\ValueObjects\LessonId;

interface LessonRepositoryInterface
{
    public function isPublished(LessonId $id): bool;

    /**
     * Возвращает сериализованный урок для снапшота.
     * Структура — на усмотрение context'а Lesson, Learn о ней не знает.
     *
     * @return array<string, mixed>
     * @throws \App\Application\Learn\Exceptions\LessonNotFound
     */
    public function getForSnapshot(LessonId $id): array;
}
