<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\Exceptions\LessonNotFound;
use App\Application\Learn\Ports\LessonRepositoryInterface;
use App\Domain\Learn\ValueObjects\LessonId;

final class FakeLessonRepository implements LessonRepositoryInterface
{
    /** @var array<int, array{published: bool, data: array<string, mixed>}> */
    private array $lessons = [];

    public function add(int $id, bool $published = true, array $data = ['title' => 'Lesson']): void
    {
        $this->lessons[$id] = ['published' => $published, 'data' => $data];
    }

    public function isPublished(LessonId $id): bool
    {
        return ($this->lessons[$id->value]['published'] ?? false) === true;
    }

    public function getForSnapshot(LessonId $id): array
    {
        if (!isset($this->lessons[$id->value])) {
            throw new LessonNotFound("Lesson {$id->value} not found");
        }

        return $this->lessons[$id->value]['data'];
    }
}
