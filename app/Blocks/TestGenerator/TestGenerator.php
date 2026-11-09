<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator;

use App\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Support\PathResolver;
use InvalidArgumentException;

/**
 * Реестр генераторов: индекс по resolver-пути ('math.algebra.equations.linearEquation').
 *
 * Путь генератора определяется его расположением в Generators/ (см. GeneratorDiscovery),
 * поэтому новые генераторы подхватываются автоматически, без ручной регистрации.
 *
 * Зарегистрирован как синглтон в TestGeneratorServiceProvider.
 */
final class TestGenerator
{
    /** @var array<string, MathProblemGeneratorInterface> */
    private array $generators = [];

    /**
     * @param iterable<MathProblemGeneratorInterface> $generators
     * @param PathResolver                            $pathResolver
     *
     * @throws InvalidArgumentException если два генератора дают одинаковый путь.
     */
    public function __construct(
        iterable $generators,
        PathResolver $pathResolver,
    ) {
        foreach ($generators as $generator) {
            $path = $pathResolver->pathFor($generator::class);

            if ($path === null) {
                throw new InvalidArgumentException(
                    sprintf('No resolver path for generator class "%s".', $generator::class)
                );
            }

            if (isset($this->generators[$path])) {
                throw new InvalidArgumentException(
                    sprintf('Duplicate generator for resolver path "%s".', $path)
                );
            }

            $this->generators[$path] = $generator;
        }
    }

    /**
     * Генерирует задание по resolver-пути.
     *
     * @param string $path       resolver-путь генератора, например 'math.algebra.equations.linearEquation'.
     * @param float  $difficulty от 0.0 (просто) до 1.0 (сложно).
     *
     * @throws InvalidArgumentException если difficulty вне [0.0, 1.0] или путь не найден.
     */
    public function generate(string $path, float $difficulty): MathProblem
    {
        if ($difficulty < 0.0 || $difficulty > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Difficulty must be between 0 and 1, %f given.', $difficulty)
            );
        }

        $path = self::normalize($path);

        if (! isset($this->generators[$path])) {
            throw new InvalidArgumentException(
                sprintf(
                    'No generator registered for resolver path "%s". Available: %s.',
                    $path,
                    $this->availablePaths() === []
                        ? '<none>'
                        : implode(', ', $this->availablePaths()),
                )
            );
        }

        return $this->generators[$path]->generate($difficulty);
    }

    /**
     * Есть ли генератор по данному пути.
     */
    public function supports(string $path): bool
    {
        return isset($this->generators[self::normalize($path)]);
    }

    /**
     * Список зарегистрированных resolver-путей.
     *
     * @return string[]
     */
    public function availablePaths(): array
    {
        return array_keys($this->generators);
    }

    private static function normalize(string $path): string
    {
        return trim($path, '. ');
    }
}