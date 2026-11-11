<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Resolvers;

use App\Domain\Blocks\TestGenerator\Discovery\GeneratorDiscovery;

/**
 * Карта resolver-путей (path => класс генератора).
 *
 * Основа - GeneratorDiscovery (сканирует Generators/). Путь - основной
 * идентификатор генератора, отдельного topic() больше нет.
 *
 * matchedPath() - точное совпадение пути: 'math.algebra.equations.linearEquation'
 * вернёт сам себя, а несуществующий 'math.algebra.equations' вернёт null.
 */
final class TestResolverMap
{
    /** @var array<string, class-string> */
    private readonly array $paths;

    public function __construct(GeneratorDiscovery $discovery)
    {
        $this->paths = $discovery->discover();
    }

    /**
     * Точный путь, если он зарегистрирован; иначе null.
     */
    public function matchedPath(string $path): ?string
    {
        $path = trim($path, '.');

        return isset($this->paths[$path]) ? $path : null;
    }

    /**
     * Класс генератора по точному пути; null - если не найден.
     *
     * @return class-string|null
     */
    public function classFor(string $path): ?string
    {
        return $this->paths[trim($path, '.')] ?? null;
    }

    /**
     * Субъект (первый сегмент пути): 'math.algebra.equations' => 'math'.
     */
    public function subjectFor(string $path): string
    {
        return explode('.', trim($path, '.'), 2)[0];
    }

    /**
     * Все пути с классами.
     *
     * @return list<array{path:string, class:class-string}>
     */
    public function all(): array
    {
        $out = [];

        foreach ($this->paths as $path => $class) {
            $out[] = ['path' => $path, 'class' => $class];
        }

        return $out;
    }

    /**
     * Список известных путей.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->paths);
    }
}
