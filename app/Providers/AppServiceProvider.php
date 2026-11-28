<?php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use App\Support\Testing\EnsureTestingDatabase;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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

        // Границы транзакций для Application-слоя.
        $this->app->bind(
            \App\Application\Shared\TransactionManagerInterface::class,
            \App\Infrastructure\Shared\EloquentTransactionManager::class,
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
        // Запись сырых ответов (штрихи) в learns.answers.
        $this->app->bind(
            \App\Application\Learn\Ports\LearnAnswersWriterInterface::class,
            \App\Infrastructure\Persistence\Repositories\EloquentLearnAnswersWriter::class,
        );

        // ===== Домен «уроки и задания» (DDD-слои) =====
        //
        // Эти четыре порта — write-side для контекстов Lessons / LessonBlocks /
        // Tasks / TasksProc. Их реализации скрывают физическую схему:
        //   - таблица lesson_blocks играет две роли (own-блок / глобальное задание);
        //   - позиция задания в уроке — в pivot lesson_lesson_block_as_test;
        //   - таксономии задания — в pivot lesson_block_taxonomy;
        //   - порядок блоков требует двухфазного SAFE_OFFSET-сдвига (PostgreSQL UNIQUE).
        //
        // Learn-порт LessonRepositoryInterface (выше) — отдельный, read-only:
        // он отвечает только за isPublished(). Не путать.
        $this->app->bind(
            \App\Application\Lessons\Ports\LessonRepositoryInterface::class,
            \App\Infrastructure\Persistence\Repositories\EloquentLessonAggregateRepository::class,
        );
        // Read-side: списки/каталог + PostgreSQL-full-text по урокам.
        $this->app->bind(
            \App\Application\Lessons\Ports\LessonsQueryInterface::class,
            \App\Infrastructure\Persistence\Queries\EloquentLessonsQuery::class,
        );
        // Read-side справочника типов урока (для select'а `type`).
        $this->app->bind(
            \App\Application\Lessons\Ports\LessonTypeQueryInterface::class,
            \App\Infrastructure\Persistence\Queries\EloquentLessonTypeQuery::class,
        );
        // Read-side контекста Users: постраничный список с поиском и ролями.
        $this->app->bind(
            \App\Application\Users\Ports\UsersQueryInterface::class,
            \App\Infrastructure\Persistence\Queries\EloquentUsersQuery::class,
        );
        // Read-side контекста Community: агрегаты по графу user_relations.
        $this->app->bind(
            \App\Application\Community\Ports\RelationsQueryInterface::class,
            \App\Infrastructure\Community\Persistence\EloquentRelationsQuery::class,
        );
        // Read-side: стандартные (database) уведомления получателя.
        // Через декоратор, который подмешивает в `data` RequestedAction
        // актуальные status/decision/recipient_role; базовое чтение
        // `notifications` — EloquentNotificationsQuery.
        $this->app->bind(
            \App\Application\Notifications\Ports\NotificationsQueryInterface::class,
            fn ($app) => new \App\Infrastructure\Notifications\RequestedActionNotificationsQuery(
                $app->make(\App\Infrastructure\Persistence\Queries\EloquentNotificationsQuery::class),
            ),
        );
        // Write-side: отметка о прочтении стандартных (database) уведомлений.
        $this->app->bind(
            \App\Application\Notifications\Ports\NotificationsWriteInterface::class,
            \App\Infrastructure\Notifications\EloquentNotificationsWrite::class,
        );
        // Read-side: срез «актуальных уроков» (learns пользователя) по уроку.
        $this->app->bind(
            \App\Application\Learn\Ports\ActualLessonScopeBuilderInterface::class,
            \App\Infrastructure\Persistence\Queries\EloquentActualLessonScopeBuilder::class,
        );
        // Read-side: последняя оценка по уроку для пользователя (срез grades).
        $this->app->bind(
            \App\Application\Learn\Ports\ActualLessonGradeQueryInterface::class,
            \App\Infrastructure\Persistence\Queries\EloquentActualLessonGradeQuery::class,
        );
        $this->app->bind(
            \App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface::class,
            \App\Infrastructure\Persistence\Repositories\EloquentLessonBlockRepository::class,
        );
        $this->app->bind(
            \App\Application\Tasks\Ports\TaskRepositoryInterface::class,
            \App\Infrastructure\Persistence\Repositories\EloquentTaskRepository::class,
        );
        $this->app->bind(
            \App\Application\TasksProc\Ports\ProcTaskRepositoryInterface::class,
            \App\Infrastructure\Persistence\Repositories\EloquentProcTaskRepository::class,
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
                    return new \App\Infrastructure\Learn\HashSnapshotResolver(
                        assembler: $app->make(\App\Application\Learn\Ports\LessonSnapshotAssemblerInterface::class),
                        cache: $app->make(\Illuminate\Contracts\Cache\Repository::class),
                        prefix: (string) config('learn.snapshot.prefix', 'learns/snapshots'),
                    );
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

        // Чтение зафиксированного снапшота по хэшу (для отдачи в HTTP).
        $this->app->bind(
            \App\Application\Learn\Ports\SnapshotReaderInterface::class,
            function ($app) {
                $disk = (string) config('learn.snapshot.disk', 's3_private');
                $prefix = (string) config('learn.snapshot.prefix', 'learns/snapshots');

                // S3-режим: приватный диск + fallback на store-диск FileSets.
                // Hash-режим — без S3: снапшоты лежат на store-диске.
                return config('learn.snapshot.driver', 's3') === 's3'
                    ? new \App\Infrastructure\Learn\S3SnapshotReader(
                        filesystems: $app->make(\Illuminate\Contracts\Filesystem\Factory::class),
                        disk: $disk,
                        prefix: $prefix,
                    )
                    : new \App\Infrastructure\Learn\FileStorageSnapshotReader(prefix: $prefix);
            },
        );

        // История статусов: синхронная запись в learn_statuses.
        $this->app->bind(
            \App\Application\Learn\Ports\StatusLoggerInterface::class,
            \App\Infrastructure\Learn\DbStatusLogger::class,
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
        // Гейт `artisan test --env=...` / `pest --env=...`: рабочая база
        // защищена от RefreshDatabase. Регистрация через withEvents(discover)
        // не работает (автопоиск смотрит в app/Listeners), а withEvents()
        // в этом релизе принимает только $discover — поэтому Event::listen.
        Event::listen(
            CommandStarting::class,
            [EnsureTestingDatabase::class, 'listen'],
        );

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
