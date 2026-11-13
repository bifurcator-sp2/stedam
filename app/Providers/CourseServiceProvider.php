<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Course\Ports\CourseAssignmentRepositoryInterface;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Course\Ports\LearnPortInterface;
use App\Application\Course\Ports\LessonReaderInterface;
use App\Application\Course\Strategies\AdmissionStrategyInterface;
use App\Application\Course\Strategies\CompletionStrategyInterface;
use App\Application\Course\Strategies\GradeStrategyInterface;
use App\Domain\Course\Events\CourseAssignmentCreated;
use App\Domain\Course\Events\CourseAssignmentRemoved;
use App\Domain\Course\Events\CourseChanged;
use App\Domain\Learn\Events\LearnStatusChanged;
use App\Infrastructure\Course\Adapters\LearnAdapter;
use App\Infrastructure\Course\Adapters\LessonReaderAdapter;
use App\Infrastructure\Course\Persistence\EloquentCourseAssignmentRepository;
use App\Infrastructure\Course\Persistence\EloquentCourseRepository;
use App\Listeners\Course\EnrollOnAssignmentCreated;
use App\Listeners\Course\RecalculateOnCourseChanged;
use App\Listeners\Course\RecalculateOnLessonGraded;
use App\Listeners\Course\UnenrollOnAssignmentRemoved;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Биндинги домена курсов.
 *
 * Интерфейсы Application → реализации Infrastructure. Стратегии берутся
 * из config/course.php, чтобы менять правило без правки кода.
 */
final class CourseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CourseRepositoryInterface::class, EloquentCourseRepository::class);
        $this->app->bind(
            CourseAssignmentRepositoryInterface::class,
            EloquentCourseAssignmentRepository::class,
        );
        $this->app->bind(LearnPortInterface::class, LearnAdapter::class);
        $this->app->bind(LessonReaderInterface::class, LessonReaderAdapter::class);

        $this->bindStrategy(GradeStrategyInterface::class);
        $this->bindStrategy(CompletionStrategyInterface::class);
        $this->bindStrategy(AdmissionStrategyInterface::class);
    }

    public function boot(): void
    {
        // Слушатели доменных событий курсов.
        // Все — ShouldQueueAfterCommit: реагируют только на зафиксированные
        // изменения и не тормозят HTTP-запрос.
        Event::listen(CourseAssignmentCreated::class, EnrollOnAssignmentCreated::class);
        Event::listen(CourseAssignmentRemoved::class, UnenrollOnAssignmentRemoved::class);
        Event::listen(CourseChanged::class, RecalculateOnCourseChanged::class);

        // Межконтекстная связка: Learn публикует LearnStatusChanged (§17.1),
        // курсы пересчитывают прогресс ученика после выставления оценки.
        // Слушатель сам отсеивает все статусы, кроме graded.
        Event::listen(LearnStatusChanged::class, RecalculateOnLessonGraded::class);
    }

    /**
     * @param class-string $contract
     */
    private function bindStrategy(string $contract): void
    {
        $this->app->bind($contract, function (Container $app) use ($contract): object {
            // FQCN содержит бэкслэши, и вложенный ключ с точкой/бэкслэшем
            // Laravel не находит. Поэтому берём карту целиком и потом
            // собираем ключ 'course.' + относительный путь.
            $configKey = config('course.contracts')[$contract] ?? null;

            /** @var class-string|null $implementation */
            $implementation = $configKey === null ? null : config('course.' . $configKey);

            if (!is_string($implementation) || $implementation === '') {
                throw new \LogicException(
                    "Course strategy is not configured for {$contract}"
                );
            }

            return $app->make($implementation);
        });
    }
}
