<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Container;

use App\Domain\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Ports\ProblemGeneratorInterface;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Реализация порта генерации: индекс «resolver-путь => экземпляр генератора».
 *
 * Путь генератора передаётся снаружи (его знает GeneratorDiscovery),
 * поэтому сами генераторы не обращаются к контейнеру и не зависят от
 * расположения файлов.
 *
 * Регистрируется синглтоном в TestGeneratorServiceProvider: экземпляры
 * генераторов stateless, индекс строится один раз.
 */
final class InMemoryProblemGenerator implements ProblemGeneratorInterface
{
    /** @var array<string, MathProblemGeneratorInterface> */
    private array $generators = [];

    /**
     * @param array<string, MathProblemGeneratorInterface> $generators путь => экземпляр
     */
    public function __construct(array $generators)
    {
        $this->generators = $generators;
    }

    public function generate(string $path, float $difficulty, ?Randomizer $rng = null): MathProblem
    {
        $path = self::normalize($path);

        if (! isset($this->generators[$path])) {
            throw new InvalidArgumentException(sprintf(
                'No generator registered for resolver path "%s". Available: %s.',
                $path,
                $this->generators === [] ? '<none>' : implode(', ', array_keys($this->generators)),
            ));
        }

        return $this->generators[$path]->generate($difficulty, $rng);
    }

    public function supports(string $path): bool
    {
        return isset($this->generators[self::normalize($path)]);
    }

    private static function normalize(string $path): string
    {
        return trim($path, '. ');
    }
}
