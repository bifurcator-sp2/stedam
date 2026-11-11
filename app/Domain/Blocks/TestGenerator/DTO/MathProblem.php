<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\DTO;

use InvalidArgumentException;

/**
 * Иммутабельный DTO сгенерированного математического задания.
 *
 * Поля question и solution содержат текст с inline/display KaTeX-разметкой
 * (\\( ... \\) и \\[ ... \\]). Формат подобран так, чтобы результат корректно
 * рендерился через katex/contrib/auto-render на фронтенде и не конфликтовал
 * с Blade-интерполяцией ($...$ внутри Blade-шаблонов).
 */
final readonly class MathProblem
{
    public function __construct(
        /** Текст задания (может содержать inline KaTeX). */
        public string $question,

        /** Эталонное решение: текст + пошаговые формулы в KaTeX. */
        public string $solution,

        /** Финальный ответ в виде простого текста (без разметки). */
        public string $answer,

        /** Ключ темы, к которой относится задание (например, 'arithmetic'). */
        public string $topic,

        /** Сложность задания в диапазоне 0.0 … 1.0. */
        public float $difficulty,
    ) {
        if ($difficulty < 0.0 || $difficulty > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Difficulty must be between 0 and 1, %f given.', $difficulty)
            );
        }

        if (trim($question) === '') {
            throw new InvalidArgumentException('Question must not be empty.');
        }

        if (trim($solution) === '') {
            throw new InvalidArgumentException('Solution must not be empty.');
        }

        if (trim($answer) === '') {
            throw new InvalidArgumentException('Answer must not be empty.');
        }

        if (trim($topic) === '') {
            throw new InvalidArgumentException('Topic must not be empty.');
        }
    }

    /**
     * Удобное представление для JSON-ответов API.
     *
     * @return array{question:string,solution:string,answer:string,topic:string,difficulty:float}
     */
    public function toArray(): array
    {
        return [
            'question'   => $this->question,
            'solution'   => $this->solution,
            'answer'     => $this->answer,
            'topic'      => $this->topic,
            'difficulty' => $this->difficulty,
        ];
    }
}
