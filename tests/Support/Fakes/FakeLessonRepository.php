<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\Ports\LessonRepositoryInterface;
use App\Domain\Learn\ValueObjects\LessonId;

/**
 * Фейк доступа Learn-контекста к урокам: управляемый набор опубликованных.
 */
final class FakeLessonRepository implements LessonRepositoryInterface
{
    /** @var array<int, bool> */
    private array $published = [];

    public function publish(int $lessonId, bool $published = true): void
    {
        $this->published[$lessonId] = $published;
    }

    /**
     * Синоним publish() — сохранён для совместимости со старыми тестами.
     */
    public function add(int $lessonId, bool $published = true): void
    {
        $this->publish($lessonId, $published);
    }

    public function isPublished(LessonId $id): bool
    {
        return $this->published[$id->value] ?? false;
    }
}
