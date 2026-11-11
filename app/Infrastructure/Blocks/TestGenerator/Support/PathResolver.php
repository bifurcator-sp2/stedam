<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Support;

use App\Domain\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Infrastructure\Blocks\TestGenerator\Discovery\GeneratorDiscovery;
use InvalidArgumentException;

/**
 * Резолвер resolver-пути: 'math.algebra.equations.linearEquation' => FQCN генератора.
 *
 * Тонкая обёртка над GeneratorDiscovery (без повторного сканирования).
 * Путь = расположение файла в Generators/, поэтому новые генераторы
 * подхватываются автоматически — регистрировать ничего не нужно.
 */
final class PathResolver
{
    public function __construct(
        private readonly GeneratorDiscovery $discovery,
    ) {
    }

    /**
     * Карта путь => класс.
     *
     * @return array<string, class-string<MathProblemGeneratorInterface>>
     */
    public function all(): array
    {
        return $this->discovery->discover();
    }

    /**
     * Точный матч по пути. null — если такого генератора нет.
     *
     * @return class-string<MathProblemGeneratorInterface>|null
     */
    public function find(string $path): ?string
    {
        $path = trim($path, '.');

        return $path === '' ? null : ($this->discovery->discover()[$path] ?? null);
    }

    /**
     * Точный матч или исключение со списком доступных путей.
     *
     * @return class-string<MathProblemGeneratorInterface>
     */
    public function classFor(string $path): string
    {
        $class = $this->find($path);

        if ($class === null) {
            $available = array_keys($this->discovery->discover());

            throw new InvalidArgumentException(sprintf(
                'No generator for resolver path "%s". Available: %s.',
                $path,
                $available === [] ? '<none>' : implode(', ', $available),
            ));
        }

        return $class;
    }

    /**
     * Обратное преобразование: FQCN => resolver-путь.
     */
    public function pathFor(string $fqcn): ?string
    {
        $path = array_search($fqcn, $this->discovery->discover(), true);

        return $path === false ? null : $path;
    }
}
