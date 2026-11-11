<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators;

use App\Domain\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Random\Seed;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use App\Domain\Blocks\TestGenerator\Support\PathResolver;
use InvalidArgumentException;
use Random\Randomizer;
use RuntimeException;

/**
 * Базовый абстрактный класс для MathProblemGeneratorInterface.
 *
 * Валидирует difficulty и передаёт в build() вместе с источником
 * псевдослучайности — Randomizer. Конкретный RNG приходит извне
 * (см. Random\Seed), поэтому один и тот же генератор с одним и тем же
 * сидом всегда строит одно и то же задание.
 *
 * Так же предоставляет path() — resolver-путь генератора, вычисляемый
 * из имени/расположения класса.
 */
abstract class GeneratorBase implements MathProblemGeneratorInterface
{
    /** Кэш вычисленного resolver-пути. */
    private ?string $path = null;

    /**
     * @param Randomizer|null $rng сидированный источник случайности; null —
     *                             несидированный Mt19937 (для /tasks/random).
     */
    final public function generate(float $difficulty, ?Randomizer $rng = null): MathProblem
    {
        if ($difficulty < 0.0 || $difficulty > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Difficulty must be between 0 and 1, %f given.', $difficulty)
            );
        }

        // Randomizer — мутабельный объект, и его нельзя клонировать
        // (__clone запрещён в самом классе). Поэтому вызывающий не должен
        // переиспользовать один инстанс между вызовами: контракт —
        // «один сид (int) → один Randomizer». См. Seed::fromInt().
        return $this->build($difficulty, $rng ?? Seed::unseeded());
    }

    /**
     * Построить задание заданной сложности.
     *
     * @param Randomizer $rng единственный источник случайности: всё, что
     *                        попадает в условие и ответ, берётся из него.
     */
    abstract protected function build(float $difficulty, Randomizer $rng): MathProblem;

    /**
     * Resolver-путь текущего генератора: 'math.algebra.equations.linearEquation'.
     * Вычисляется один раз и кэшируется на инстансе.
     */
    protected function path(): string
    {
        if ($this->path === null) {
            $class = static::class;

            $resolved = app(PathResolver::class)->pathFor($class);

            if ($resolved === null) {
                throw new RuntimeException(sprintf(
                    'No resolver path found for generator class "%s". '
                    . 'Make sure it lives under Generators/ and implements the interface.',
                    $class,
                ));
            }

            $this->path = $resolved;
        }

        return $this->path;
    }

    /** Случайное целое [min, max], включительно с обеих сторон. */
    protected function rnd(Randomizer $rng, int $min, int $max): int
    {
        return $rng->getInt($min, max($min, $max));
    }

    /** @template T @param list<T> $options @return T */
    protected function pick(Randomizer $rng, array $options): mixed
    {
        return $options[$rng->getInt(0, count($options) - 1)];
    }

    /** Форматирование числа для KaTeX (без лишних нулей). */
    protected function num(int|float $value, int $precision = 2): string
    {
        return KaTeX::number($value, $precision);
    }
}
