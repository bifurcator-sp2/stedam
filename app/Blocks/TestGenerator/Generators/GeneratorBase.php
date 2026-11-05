<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators;

use App\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Support\KaTeX;
use InvalidArgumentException;

/**
 * Тонкая обёртка над MathProblemGeneratorInterface.
 *
 * Валидирует difficulty и делегирует в build(). Подкласс реализует только
 * build() и static topic(); вся обвязка (KaTeX::number, rnd, pick) — здесь.
 */
abstract class GeneratorBase implements MathProblemGeneratorInterface
{
    final public function generate(float $difficulty): MathProblem
    {
        if ($difficulty < 0.0 || $difficulty > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Difficulty must be between 0 and 1, %f given.', $difficulty)
            );
        }
        return $this->build($difficulty);
    }

    abstract protected function build(float $difficulty): MathProblem;

    /** Случайное целое [min, max], толерантное к перепутанным границам. */
    protected function rnd(int $min, int $max): int
    {
        return random_int($min, max($min, $max));
    }

    /** @template T @param list<T> $options @return T */
    protected function pick(array $options): mixed
    {
        return $options[array_rand($options)];
    }

    /** Форматирование числа для KaTeX (без хвостовых нулей). */
    protected function num(int|float $value, int $precision = 2): string
    {
        return KaTeX::number($value, $precision);
    }
}
