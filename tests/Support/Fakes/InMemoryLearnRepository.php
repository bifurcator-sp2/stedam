<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\Exceptions\LearnNotFound;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\UserId;

final class InMemoryLearnRepository implements LearnRepositoryInterface
{
    /** @var array<int, Learn> */
    private array $items = [];
    private int $nextId = 1;

    public function save(Learn $learn): Learn
    {
        if ($learn->id() === null) {
            $learn = $learn->withId(new LearnId($this->nextId++));
        }
        $this->items[$learn->id()->value] = $learn;

        return $learn;
    }

    public function getById(LearnId $id): Learn
    {
        return $this->items[$id->value]
            ?? throw new LearnNotFound("Learn {$id->value} not found");
    }

    public function findActiveByUserAndLesson(UserId $userId, LessonId $lessonId): ?Learn
    {
        foreach ($this->items as $learn) {
            if ($learn->userId()->equals($userId)
                && $learn->lessonId()->value === $lessonId->value
                && !$learn->status()->isTerminal()
            ) {
                return $learn;
            }
        }

        return null;
    }
}
