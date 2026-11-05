<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Resolvers;

use App\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Blocks\TestGenerator\Discovery\GeneratorDiscovery;
use App\Blocks\TestGenerator\TestGenerator;

/**
 * Мост «адрес узла дерева ↔ ключ стратегии».
 *
 * Источник данных — GeneratorDiscovery (скан папки Generators).
 * Никаких config-маппингов и fallback-ов: единственный источник правды
 * о доступных путях — файловая структура + топики классов.
 *
 * Префиксный алгоритм:
 *   'math.algebra.equals' → 'math.algebra.equals', 'math.algebra', 'math'
 *   Первое совпадение в карте выигрывает.
 *
 * Возвращаемое значение — topic() найденного генератора, что удобно
 * для TestGenerator::generate() (он работает с топиками).
 */
final class TestResolverMap
{
    /** @var array<string, class-string<MathProblemGeneratorInterface>> */
    private readonly array $paths;

    public function __construct(
        private readonly TestGenerator $generators,
        GeneratorDiscovery $discovery,
    ) {
        $this->paths = $discovery->discover();
    }

/**
 * Резолвит адрес узла в topic генератора.
 *
 * @return string|null topic() найденного генератора либо null,
 *                     если ни один префикс не совпал.
 */
public function topicFor(string $resolverPath): ?string
{
    $resolverPath = trim($resolverPath, '.');
    if ($resolverPath === '') {
        return null;
    }

    $parts = explode('.', $resolverPath);

    while ($parts !== []) {
        $prefix = implode('.', $parts);

        if (isset($this->paths[$prefix])) {
            /** @var class-string<MathProblemGeneratorInterface> $class */
            $class = $this->paths[$prefix];

            return $class::topic();
        }

        array_pop($parts);
    }

    return null;
}

/**
 * Полный адрес (path) ближайшего зарегистрированного предка.
 * Нужен для аудита и для include_nodes в /api/tasks/resolvers.
 */
public function matchedPath(string $resolverPath): ?string
{
    $resolverPath = trim($resolverPath, '.');
    $parts = explode('.', $resolverPath);

    while ($parts !== []) {
        $prefix = implode('.', $parts);
        if (isset($this->paths[$prefix])) {
            return $prefix;
        }
        array_pop($parts);
    }

    return null;
}

public function subjectFor(string $resolverPath): string
{
    return explode('.', trim($resolverPath, '.'), 2)[0];
}

/**
 * @return list<array{path:string, topic:string, class:class-string}>
 */
public function all(): array
{
    $out = [];

    foreach ($this->paths as $path => $class) {
        /** @var class-string<MathProblemGeneratorInterface> $class */
        $out[] = [
            'path'  => $path,
            'topic' => $class::topic(),
            'class' => $class,
        ];
    }

    return $out;
}

/**
 * Проверка: все пути в карте разрешаются в зарегистрированные топики.
 *
 * @return list<string>
 */
public function validate(): array
{
    $errors    = [];
    $available = $this->generators->availableTopics();

    foreach ($this->paths as $path => $class) {
        /** @var class-string<MathProblemGeneratorInterface> $class */
        $topic = $class::topic();

        if (! in_array($topic, $available, true)) {
            $errors[] = sprintf(
                'Path "%s" → topic "%s", но топик не зарегистрирован в TestGenerator. '
                . 'Проверьте регистрацию генератора в провайдере.',
                $path,
                $topic,
            );
        }
    }

    return $errors;
}
}
