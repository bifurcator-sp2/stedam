<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Domain\Blocks\TestGenerator\Ports\GeneratorCatalogInterface;
use App\Domain\Blocks\TestGenerator\Ports\ProblemGeneratorInterface;
use App\Domain\Blocks\TestGenerator\Services\GenerateProblemService;
use App\Infrastructure\Blocks\TestGenerator\Container\FilesystemGeneratorCatalog;
use App\Infrastructure\Blocks\TestGenerator\Container\InMemoryProblemGenerator;
use App\Infrastructure\Blocks\TestGenerator\Discovery\GeneratorDiscovery;
use App\Infrastructure\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Infrastructure\Blocks\TestGenerator\Resolvers\TestResolverMap;
use App\Infrastructure\Blocks\TestGenerator\Support\PathResolver;
use Illuminate\Support\ServiceProvider;

final class TestGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeneratorDiscovery::class, function (): GeneratorDiscovery {
            return new GeneratorDiscovery(
                basePath:      app_path('Infrastructure/Blocks/TestGenerator/Generators'),
                baseNamespace: 'App\\Infrastructure\\Blocks\\TestGenerator\\Generators',
            );
        });

        $this->app->singleton(PathResolver::class, function ($app): PathResolver {
            return new PathResolver($app->make(GeneratorDiscovery::class));
        });

        // Порт каталога: список доступных resolver-путей (для /api/catalog и т.п.).
        $this->app->singleton(GeneratorCatalogInterface::class, function ($app): GeneratorCatalogInterface {
            return new FilesystemGeneratorCatalog($app->make(GeneratorDiscovery::class));
        });

        // Порт генерации: индекс «путь => генератор». Путь проставляется здесь,
        // поэтому генераторы больше не обращаются к контейнеру.
        $this->app->singleton(ProblemGeneratorInterface::class, function ($app): ProblemGeneratorInterface {
            /** @var GeneratorDiscovery $discovery */
            $discovery = $app->make(GeneratorDiscovery::class);

            $instances = [];
            foreach ($discovery->discover() as $path => $class) {
                /** @var class-string<MathProblemGeneratorInterface> $class */
                $generator = $app->make($class);

                if ($generator instanceof GeneratorBase) {
                    $generator->withPath($path);
                }

                $instances[$path] = $generator;
            }

            return new InMemoryProblemGenerator($instances);
        });

        // Доменный сценарий генерации (валидация difficulty + нормализация пути).
        $this->app->singleton(GenerateProblemService::class, function ($app): GenerateProblemService {
            return new GenerateProblemService($app->make(ProblemGeneratorInterface::class));
        });

        $this->app->singleton(TestResolverMap::class, function ($app): TestResolverMap {
            return new TestResolverMap(
                $app->make(GeneratorDiscovery::class),
            );
        });
    }
}
