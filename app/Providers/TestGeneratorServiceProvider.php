<?php

declare(strict_types=1);

namespace App\Providers;

use App\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Blocks\TestGenerator\Discovery\GeneratorDiscovery;
use App\Blocks\TestGenerator\Resolvers\TestResolverMap;
use App\Blocks\TestGenerator\TestGenerator;
use Illuminate\Support\ServiceProvider;

final class TestGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeneratorDiscovery::class, function (): GeneratorDiscovery {
            return new GeneratorDiscovery(
                basePath:      app_path('Blocks/TestGenerator/Generators'),
                baseNamespace: 'App\\Blocks\\TestGenerator\\Generators',
            );
        });

        $this->app->singleton(TestGenerator::class, function ($app): TestGenerator {
            /** @var GeneratorDiscovery $discovery */
            $discovery = $app->make(GeneratorDiscovery::class);

            $instances = [];
            foreach ($discovery->discover() as $class) {
                /** @var class-string<MathProblemGeneratorInterface> $class */
                $instances[] = $app->make($class);
            }

            return new TestGenerator($instances);
        });

        $this->app->singleton(TestResolverMap::class, function ($app): TestResolverMap {
            return new TestResolverMap(
                $app->make(TestGenerator::class),
                $app->make(GeneratorDiscovery::class),
            );
        });
    }
}
