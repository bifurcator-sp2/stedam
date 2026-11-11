<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\DTOs\LearnSeed;
use App\Application\Learn\Ports\LearnQuestionsBuilderInterface;
use App\Domain\Learn\ValueObjects\LearnQuestions;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\QuestionItem;

/**
 * Фейк генератора заданий: возвращает фиксированный набор по lessonId.
 *
 * По умолчанию — одно задание с index=1 (совпадает с answerImages()).
 * Можно задать свой набор через ->withQuestions() или полностью
 * переопределить фабрику ответов через ->withFactory().
 *
 * Считает вызовы (->calls) — чтобы проверять, что StartLearnAction
 * не перегенерирует задачи для уже идущего learn.
 */
final class FakeLearnQuestionsBuilder implements LearnQuestionsBuilderInterface
{
    public int $calls = 0;

    /** @var array<int, LearnQuestions> */
    private array $byLesson = [];

    /** @var null|callable(LearnSeed, LessonId): LearnQuestions */
    private $factory = null;

    public function withQuestions(int $lessonId, LearnQuestions $questions): self
    {
        $this->byLesson[$lessonId] = $questions;

        return $this;
    }

    /**
     * @param callable(LearnSeed, LessonId): LearnQuestions $factory
     */
    public function withFactory(callable $factory): self
    {
        $this->factory = $factory;

        return $this;
    }

    public function buildFor(LearnSeed $seed, LessonId $lessonId): LearnQuestions
    {
        $this->calls++;

        if ($this->factory !== null) {
            return ($this->factory)($seed, $lessonId);
        }

        if (isset($this->byLesson[$lessonId->value])) {
            return $this->byLesson[$lessonId->value];
        }

        return LearnQuestions::fromItems([
            new QuestionItem(
                index: 1,
                question: "Задание для learn {$seed->learnId->value}",
                answer: '42',
                solution: '—',
                topic: 'math.test',
                difficulty: 0.5,
                seed: 1,
            ),
        ]);
    }
}
