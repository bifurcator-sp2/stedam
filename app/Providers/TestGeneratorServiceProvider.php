<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Domain\Blocks\TestGenerator\Discovery\GeneratorDiscovery;
use App\Domain\Blocks\TestGenerator\Resolvers\TestResolverMap;
use App\Domain\Blocks\TestGenerator\Support\PathResolver;
use App\Domain\Blocks\TestGenerator\TestGenerator;
use Illuminate\Support\ServiceProvider;

final class TestGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeneratorDiscovery::class, function (): GeneratorDiscovery {
            return new GeneratorDiscovery(
                basePath:      app_path('Domain/Blocks/TestGenerator/Generators'),
                baseNamespace: 'App\\Domain\\Blocks\\TestGenerator\\Generators',
            );
        });

        $this->app->singleton(PathResolver::class, function ($app): PathResolver {
            return new PathResolver($app->make(GeneratorDiscovery::class));
        });

        $this->app->singleton(TestGenerator::class, function ($app): TestGenerator {
            /** @var GeneratorDiscovery $discovery */
            $discovery = $app->make(GeneratorDiscovery::class);

            $instances = [];
            foreach ($discovery->discover() as $class) {
                /** @var class-string<MathProblemGeneratorInterface> $class */
                $instances[] = $app->make($class);
            }

            return new TestGenerator($instances, $app->make(PathResolver::class));
        });

        $this->app->singleton(TestResolverMap::class, function ($app): TestResolverMap {
            return new TestResolverMap(
                $app->make(GeneratorDiscovery::class),
            );
        });
    }
}
