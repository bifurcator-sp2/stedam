<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Container;

use App\Domain\Blocks\TestGenerator\Ports\GeneratorCatalogInterface;
use App\Infrastructure\Blocks\TestGenerator\Discovery\GeneratorDiscovery;

/**
 * Каталог генераторов поверх файлового discovery.
 *
 * Источник правды — GeneratorDiscovery: он сканирует Generators/ и вычисляет
 * resolver-путь из namespace/FQCN класса. Значит добавление нового файла
 * генератора автоматически делает его доступным в API — регистрировать
 * ничего не нужно.
 */
final readonly class FilesystemGeneratorCatalog implements GeneratorCatalogInterface
{
    public function __construct(
        private GeneratorDiscovery $discovery,
    ) {
    }

    public function availablePaths(): array
    {
        return array_keys($this->discovery->discover());
    }

    public function pathToClass(): array
    {
        return $this->discovery->discover();
    }

    public function matchedPath(string $path): ?string
    {
        $path = trim($path, '. ');

        if ($path === '') {
            return null;
        }

        return isset($this->discovery->discover()[$path]) ? $path : null;
    }

    public function subjectFor(string $path): string
    {
        return explode('.', trim($path, '. '), 2)[0];
    }
}
