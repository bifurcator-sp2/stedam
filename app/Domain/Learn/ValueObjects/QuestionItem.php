<?php

declare(strict_types=1);

namespace App\Domain\Learn\ValueObjects;

use App\Domain\Learn\Exceptions\InvalidQuestion;
use JsonSerializable;

/**
 * Сгенерированное задание, материализованное для конкретного прохождения.
 *
 * Содержит только текст: ни blob, ни изображений. Ответ ученика живёт
 * отдельно (AnswerImage) и связывается с заданием по совпадению index.
 *
 * index — позиция генеративного блока в allBlocksOrdered() + 1 (та же
 * семантика, что и у AnswerImage::index). См. §7.1 / §7.7 / §8.1 спеки.
 */
final readonly class QuestionItem implements JsonSerializable
{
    public function __construct(
        /** Позиция блока среди блоков урока (1-based). Должна совпадать с AnswerImage::index. */
        public int $index,

        /** Текст задания (может содержать KaTeX-разметку). */
        public string $question,

        /** Эталонный ответ (может быть пустым). */
        public string $answer,

        /** Решение (может быть пустым). */
        public string $solution,

        /** Resolver-путь генератора, создавшего задание. */
        public string $topic,

        /** Сложность 0.0 … 1.0. */
        public float $difficulty,

        /** Сид, которым сгенерировано задание; null — не сидировано. */
        public ?int $seed = null,
    ) {
        if ($index < 1) {
            throw new InvalidQuestion("Question index must be >= 1, {$index} given.");
        }

        if (trim($question) === '') {
            throw new InvalidQuestion('Question text must not be empty.');
        }

        if ($difficulty < 0.0 || $difficulty > 1.0) {
            throw new InvalidQuestion(
                sprintf('Difficulty must be between 0 and 1, %f given.', $difficulty),
            );
        }
    }

    /**
     * @return array{
     *     index: int,
     *     question: string,
     *     answer: string,
     *     solution: string,
     *     topic: string,
     *     difficulty: float,
     *     seed: ?int,
     * }
     */
    public function toArray(): array
    {
        return [
            'index'      => $this->index,
            'question'   => $this->question,
            'answer'     => $this->answer,
            'solution'   => $this->solution,
            'topic'      => $this->topic,
            'difficulty' => $this->difficulty,
            'seed'       => $this->seed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }
}
