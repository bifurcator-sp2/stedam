<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Services;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Ports\ProblemGeneratorInterface;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Доменный сценарий «сгенерировать задание»: валидация входа + делегирование порту.
 *
 * Здесь живёт правило, которое не зависит от способа генерации:
 * сложность обязана быть в [0.0, 1.0], путь нормализуется (убираются . и пробелы).
 * Конкретный генератор домен не знает — только порт.
 */
final readonly class GenerateProblemService
{
    public function __construct(
        private ProblemGeneratorInterface $generator,
    ) {
    }

    /**
     * @throws InvalidArgumentException если difficulty вне [0.0, 1.0]
     */
    public function generate(string $path, float $difficulty, ?Randomizer $rng = null): MathProblem
    {
        if ($difficulty < 0.0 || $difficulty > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Difficulty must be between 0 and 1, %f given.', $difficulty)
            );
        }

        return $this->generator->generate(self::normalizePath($path), $difficulty, $rng);
    }

    public static function normalizePath(string $path): string
    {
        return trim($path, '. ');
    }
}
