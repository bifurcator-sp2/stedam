<?php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Биндинги FileUrlResolver и FileStorage регистрирует
        // Bifurcator\FileSets\FileSetsServiceProvider (пакет).

        // Контекст просмотра прохождений (роли + граф владения).
        $this->app->bind(
            \App\Application\Learn\Context\ContextResolverInterface::class,
            \App\Infrastructure\Persistence\Context\EloquentContextResolver::class,
        );

        // Время.
        $this->app->bind(
            \App\Application\Shared\Clock::class,
            \App\Infrastructure\Shared\SystemClock::class,
        );

        // Порты контекста Learn → инфраструктурные реализации.
        $this->app->bind(
            \App\Application\Learn\Ports\LearnRepositoryInterface::class,
            \App\Infrastructure\Persistence\Repositories\EloquentLearnRepository::class,
        );
        $this->app->bind(
            \App\Application\Learn\Ports\LessonRepositoryInterface::class,
            \App\Infrastructure\Persistence\Repositories\EloquentLessonRepository::class,
        );

        // Структура урока для снапшота и поиск resolver-пути генератора.
        $this->app->bind(
            \App\Application\Learn\Ports\ProcedureResolverPathInterface::class,
            \App\Infrastructure\Learn\EloquentProcedureResolverPath::class,
        );
        $this->app->bind(
            \App\Application\Learn\Ports\LessonSnapshotAssemblerInterface::class,
            \App\Infrastructure\Learn\EloquentLessonSnapshotAssembler::class,
        );

        // Генерация уникальных задач прохождения (сид по userId+learnId).
        $this->app->bind(
            \App\Application\Learn\Ports\LearnQuestionsBuilderInterface::class,
            \App\Infrastructure\Learn\EloquentLearnQuestionsBuilder::class,
        );
        $this->app->bind(
            \App\Application\Learn\Ports\SnapshotResolverInterface::class,
            function ($app) {
                if (config('learn.snapshot.driver', 's3') === 'hash') {
                    return $app->make(\App\Infrastructure\Learn\HashSnapshotResolver::class);
                }

                return new \App\Infrastructure\Learn\S3SnapshotResolver(
                    assembler: $app->make(\App\Application\Learn\Ports\LessonSnapshotAssemblerInterface::class),
                    cache: $app->make(\Illuminate\Contracts\Cache\Repository::class),
                    filesystems: $app->make(\Illuminate\Contracts\Filesystem\Factory::class),
                    disk: (string) config('learn.snapshot.disk', 's3_private'),
                    prefix: (string) config('learn.snapshot.prefix', 'learns/snapshots'),
                    cacheTtl: (int) config('learn.snapshot.cache_ttl', 3600),
                    lockTtl: (int) config('learn.snapshot.lock_ttl', 10),
                    lockWait: (int) config('learn.snapshot.lock_wait', 5),
                );
            },
        );

        // Буфер статусов в Redis (конфигурируемый connection/key).
        $this->app->singleton(
            \App\Infrastructure\Learn\LearnStatusBuffer::class,
            fn ($app) => new \App\Infrastructure\Learn\LearnStatusBuffer(
                redis: $app->make(\Illuminate\Contracts\Redis\Factory::class),
                connection: (string) config('learn.status_logger.connection', 'default'),
                key: (string) config('learn.status_logger.buffer_key', 'learn:status:buffer'),
            ),
        );
        $this->app->bind(
            \App\Application\Learn\Ports\StatusBufferInterface::class,
            \App\Infrastructure\Learn\LearnStatusBuffer::class,
        );

        // Логгер статусов: redis-буфер (async) или синхронная запись в БД.
        $this->app->bind(
            \App\Application\Learn\Ports\StatusLoggerInterface::class,
            function ($app) {
                return config('learn.status_logger.driver', 'redis') === 'redis'
                    ? $app->make(\App\Infrastructure\Learn\RedisStatusLogger::class)
                    : $app->make(\App\Infrastructure\Learn\DbStatusLogger::class);
            },
        );

        // Список учеников учителя с агрегатами.
        $this->app->bind(
            \App\Application\Learn\Ports\TeacherStudentsQueryInterface::class,
            \App\Infrastructure\Persistence\Queries\EloquentTeacherStudentsQuery::class,
        );

        // Хранилище изображений-ответов (публичный S3, шардировано).
        $this->app->bind(
            \App\Application\Learn\Ports\AnswerStorageInterface::class,
            fn ($app) => new \App\Infrastructure\Learn\S3AnswerStorage(
                filesystems: $app->make(\Illuminate\Contracts\Filesystem\Factory::class),
                images: $app->make(\Intervention\Image\Interfaces\ImageManagerInterface::class),
                disk: (string) config('learn.answers.disk', 's3_public'),
                prefix: (string) config('learn.answers.prefix', 'answers'),
                transcode: (bool) config('learn.answers.transcode', true),
                resize: (bool) config('learn.answers.resize', true),
                maxSide: (int) config('learn.answers.max_side', 1024),
                convertToWebp: (bool) config('learn.answers.convert_to_webp', true),
                quality: (int) config('learn.answers.quality', 80),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        RateLimiter::for('files-upload', function (Request $request) {
            return Limit::perMinute(30)
                ->by(optional($request->user())->id ?: $request->ip());
        });


        // 1. Лимит для отправки писем (строгий)
        RateLimiter::for('verification-email', function (Request $request) {
            return Limit::perMinute(3) // Не более 3 писем в минуту
            ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Слишком много запросов на отправку письма. Подождите минуту.',
                        'code'    => 'too_many_emails'
                    ], 429, $headers);
                });
        });

        // 2. Лимит для проверки статуса (мягкий, чтобы не сломать Sanctum)
        RateLimiter::for('user-check', function (Request $request) {
            return Limit::perMinute(60) // 60 раз в минуту (1 раз в секунду)
            ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Слишком много проверок. Подождите немного.',
                        'code'    => 'too_many_checks'
                    ], 429, $headers);
                });
        });
    }
}
