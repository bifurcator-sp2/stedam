<?php
// packages/bifurcator/laravel-file-sets/src/FileSetsServiceProvider.php

namespace Bifurcator\FileSets;

use Bifurcator\FileSets\Contracts\FileUrlResolver;
use Bifurcator\FileSets\Contracts\ThumbnailMakerContract;
use Bifurcator\FileSets\Storage\LaravelFileStorage;
use Bifurcator\FileSets\Support\NullThumbnailMaker;
use Bifurcator\FileSets\Url\LocalUrlResolver;
use Bifurcator\FileSets\Url\S3PublicUrlResolver;
use Bifurcator\FileSets\Url\S3TemporaryUrlResolver;
use Illuminate\Support\ServiceProvider;

class FileSetsServiceProvider extends ServiceProvider
{
    /**
     * Регистрация биндингов.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/file-sets.php',
            'file-sets',
        );

        $this->registerFileUrlResolver();
        $this->registerFileStorages();
        $this->registerThumbnailMaker();
    }

    /**
     * Публикация конфига и миграций.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/file-sets.php' => config_path('file-sets.php'),
            ], 'file-sets-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'file-sets-migrations');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    // ============================================================
    // FileUrlResolver
    // ============================================================

    protected function registerFileUrlResolver(): void
    {
        $this->app->singleton(FileUrlResolver::class, function () {
            $key = config('file-sets.url_resolver', 'local');

            return match ($key) {
                's3_temporary' => new S3TemporaryUrlResolver(),
                's3_public'    => new S3PublicUrlResolver(),
                default        => new LocalUrlResolver(),
            };
        });
    }

    // ============================================================
    // FileStorage (temp + store)
    // ============================================================

    protected function registerFileStorages(): void
    {
        $this->app->singleton(FileStorageKeys::TEMP, function () {
            return new LaravelFileStorage(
                config('file-sets.temp_disk', 'public')
            );
        });

        $this->app->singleton(FileStorageKeys::STORE, function () {
            return new LaravelFileStorage(
                config('file-sets.store_disk', 'public')
            );
        });
    }

    // ============================================================
    // ThumbnailMaker
    // ============================================================

    protected function registerThumbnailMaker(): void
    {
        $this->app->singleton(ThumbnailMakerContract::class, function () {
            $class = config('file-sets.thumbnail_maker');

            if (!is_string($class) || !class_exists($class)) {
                return new NullThumbnailMaker();
            }

            return $this->app->make($class);
        });
    }
}
