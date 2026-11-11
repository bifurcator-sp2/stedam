<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Discovery;

use App\Domain\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/**
 * Автоматическое обнаружение генераторов в папке Generators.
 *
 * Рмя резолвера вычисляется из namespace и имени класса:
 *   App\Infrastructure\Blocks\TestGenerator\Generators\Math\Algebra\EqualsGenerator
 *   в†’ math.algebra.equals
 *
 * Правила:
 *   - namespace после baseNamespace → сегменты через Str::camel()
 *   - имя класса без суффикса "Generator" → Str::camel() → последний сегмент
 *   - классы, реализующие MathProblemGeneratorInterface и не абстрактные,
 *     попадают в карту
 *   - дубли путей (два класса на один адрес) → RuntimeException на этапе
 *     сборки, а не в рантайме
 *
 * Кеширование:
 *   - результат кешируется через Laravel Cache на 1 час;
 *   - в dev сбросить: `php artisan cache:forget test_generator.discovered_generators`;
 *   - в prod инвалидируется автоматически при деплое (если меняется
 *     opcache/код — то и кеш).
 */
final class GeneratorDiscovery
{
    private const CACHE_KEY_PREFIX = 'test_generator.discovered_generators';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly string $basePath,
        private readonly string $baseNamespace,
    ) {
    }

/**
 * @return array<string, class-string<MathProblemGeneratorInterface>>
 *         ['math.algebra' => '...\Math\AlgebraGenerator', ...]
 */
public function discover(): array
{
    if (! is_dir($this->basePath)) {
        throw new RuntimeException(
            sprintf('Generators directory not found: %s', $this->basePath)
        );
    }

    return cache()->remember(
        $this->cacheKey(),
        self::CACHE_TTL,
        fn (): array => $this->scan(),
    );
}

/**
 * Сброс кеша — вызывать после деплоя или при добавлении генераторов.
 */
public function flush(): void
{
    cache()->forget($this->cacheKey());
}

/**
 * Ключ кеша включает namespace: после переноса слоёв (Domain → Infrastructure)
 * старый кеш не будет ошибочно переиспользован.
 */
private function cacheKey(): string
{
    return self::CACHE_KEY_PREFIX . '.' . md5($this->baseNamespace);
}

/**
 * @return array<string, class-string<MathProblemGeneratorInterface>>
 */
private function scan(): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $this->basePath,
            RecursiveDirectoryIterator::SKIP_DOTS,
        )
    );

    $map     = [];
    $orphans = [];

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $fqcn = $this->fqcnFromFile($file->getPathname());
        if ($fqcn === null || ! class_exists($fqcn)) {
            continue;
        }

        $ref = new ReflectionClass($fqcn);
        if (! $ref->isInstantiable()
            || ! $ref->implementsInterface(MathProblemGeneratorInterface::class)
        ) {
            continue;
        }

        $path = $this->pathFromFqcn($fqcn);
        if ($path === null) {
            continue;
        }

        if (isset($map[$path])) {
            $orphans[] = sprintf('%s и %s', $map[$path], $fqcn);
            throw new RuntimeException(sprintf(
                'Duplicate resolver path "%s": %s. Переименуйте один из классов.',
                $path,
                implode(' | ', $orphans),
            ));
        }

        $map[$path] = $fqcn;
    }

    ksort($map);

    return $map;
}

private function fqcnFromFile(string $filePath): ?string
{
    $relative = substr($filePath, strlen($this->basePath) + 1);
    if ($relative === false || $relative === '') {
        return null;
    }

    $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
    $relative = substr($relative, 0, -strlen('.php'));

    return $this->baseNamespace . '\\' . $relative;
}

private function pathFromFqcn(string $fqcn): ?string
{
    $relative = substr($fqcn, strlen($this->baseNamespace) + 1);
    if ($relative === false || $relative === '') {
        return null;
    }

    $parts = explode('\\', $relative);
    $class = array_pop($parts);

    $leaf = $this->classNameToSegment((string) $class);
    if ($leaf === '') {
        return null;
    }

    $folders = array_map(
        static fn (string $f): string => Str::camel($f),
        $parts,
    );
    $folders[] = $leaf;

    return implode('.', $folders);
}

private function classNameToSegment(string $className): string
{
    if (str_ends_with($className, 'Generator')) {
        $className = substr($className, 0, -strlen('Generator'));
    }

    return Str::camel($className);
}
}
