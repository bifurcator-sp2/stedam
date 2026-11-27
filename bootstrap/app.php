<?php

use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Exceptions\LessonNotFound;
use App\Application\Learn\Exceptions\SnapshotUnavailable;
use App\Domain\Course\Exceptions\CourseAlreadyAssigned;
use App\Domain\Course\Exceptions\CourseIsPublishedEdit;
use App\Domain\Course\Exceptions\CourseLessonAlreadyInCourse;
use App\Domain\Course\Exceptions\CourseLessonNotInTheme;
use App\Domain\Course\Exceptions\CourseLocaleChangeDenied;
use App\Domain\Course\Exceptions\CourseTaxonomyChangeDenied;
use App\Domain\Course\Exceptions\CourseThemeNotFound;
use App\Domain\Course\Exceptions\InvalidCompletion;
use App\Domain\Course\Exceptions\InvalidCourseGrade;
use App\Domain\Course\Exceptions\InvalidCourseOrder;
use App\Domain\Course\Exceptions\InvalidCourseTitle;
use App\Domain\Course\Exceptions\InvalidLocale;
use App\Domain\Course\Exceptions\InvalidNextLessonThreshold;
use App\Domain\Course\Exceptions\InvalidTimeGap;
use App\Domain\Learn\Exceptions\InvalidStatusTransition;
use App\Domain\Learn\Exceptions\LearnAlreadyGraded;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\RequestedActions\Exceptions\AlreadyVotedException;
use App\Domain\RequestedActions\Exceptions\ConcurrentModificationException;
use App\Domain\RequestedActions\Exceptions\NotAConsumerException;
use App\Domain\RequestedActions\Exceptions\RequestedActionAlreadyClosedException;
use App\Domain\RequestedActions\Exceptions\RequestedActionDomainException;
use App\Domain\RequestedActions\Exceptions\RequestedActionNotFoundException;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\ImpersonateFromHeader;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            // ВАЖНО: ImpersonateFromHeader идёт ПЕРВЫМ — до SetLocale и до
            // throttle. Он подменяет пользователя запроса на target, чтобы
            // rate-limit'ы (user-check, files-upload) и все контроллеры
            // считали лимиты/данные от лица target, а не админа.
            // Подробно см. app/Http/Middleware/ImpersonateFromHeader.php.
            ImpersonateFromHeader::class,
            SetLocale::class,
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
        ]);

        //
    })
    // Не даём `artisan test` / pest прогоняться по рабочей базе: RefreshDatabase
    // сотрёт её (так 26–27.11.2026 потерялись данные laravel). Регистрация
    // через withEvents(discover: [...]) не срабатывает — автопоиск смотрит
    // в app/Listeners, поэтому слушатель CommandStarting подключается явно
    // в AppServiceProvider::boot().
    ->withExceptions(function (Exceptions $exceptions): void {
        // Application-исключения контекста Learn → HTTP.
        $exceptions->render(function (LearnNotFound $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        });
        $exceptions->render(function (LessonNotFound $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        });
        $exceptions->render(function (LearnAccessDenied $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        });
        $exceptions->render(function (SnapshotUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        });
        $exceptions->render(function (InvalidStatusTransition $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
        $exceptions->render(function (LearnAlreadyGraded $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        // Исключения контекста Courses → HTTP (docs/course-api-spec.md §0.5).
        // Формат: {message, code, ...extra}. code — машиночитаемый идентификатор,
        // чтобы фронт не парсил текст сообщения.
        $courseErrors = [
            // 403
            CourseAccessDenied::class => [403, 'course_access_denied'],
            // 404
            CourseNotFound::class => [404, 'course_not_found'],
            App\Domain\Course\Exceptions\CourseNotFound::class => [404, 'course_not_found'],
            CourseThemeNotFound::class => [404, 'course_theme_not_found'],
            CourseLessonNotInTheme::class => [404, 'course_lesson_not_in_theme'],
            // 409
            CourseAlreadyAssigned::class => [409, 'course_already_assigned'],
            CourseLessonAlreadyInCourse::class => [409, 'course_lesson_already_in_course'],
            // 422
            CourseLocaleChangeDenied::class => [422, 'course_locale_change_denied'],
            CourseIsPublishedEdit::class => [422, 'course_is_published_edit'],
            CourseTaxonomyChangeDenied::class => [422, 'course_taxonomy_change_denied'],
            InvalidCourseOrder::class => [422, 'invalid_course_order'],
            InvalidCourseGrade::class => [422, 'invalid_course_grade'],
            InvalidCompletion::class => [422, 'invalid_completion'],
            InvalidNextLessonThreshold::class => [422, 'invalid_next_lesson_threshold'],
            InvalidTimeGap::class => [422, 'invalid_time_gap'],
            // VO-исключения: голый InvalidArgumentException давал бы 500.
            InvalidLocale::class => [422, 'invalid_locale'],
            InvalidCourseTitle::class => [422, 'invalid_course_title'],
        ];

        foreach ($courseErrors as $exceptionClass => [$status, $code]) {
            $exceptions->render(function (Throwable $e) use ($exceptionClass, $status, $code) {
                if (! $e instanceof $exceptionClass) {
                    return null;
                }

                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $code,
                ], $status);
            });
        }

        // RequestedActions (2.13, 2.15 п.9/10/14/26). Порядок важен:
        // специализированные исключения проверяются раньше общего
        // RequestedActionDomainException (он их родитель в части случаев).
        $requestedActionErrors = [
            // 403 — пользователь не входит в payload.consumers
            NotAConsumerException::class => [403, 'NOT_A_CONSUMER'],
            // 404 — агрегат не найден
            RequestedActionNotFoundException::class => [404, 'REQUESTED_ACTION_NOT_FOUND'],
            // 409 — закрыт или повторный голос
            AlreadyVotedException::class => [409, 'ALREADY_VOTED'],
            RequestedActionAlreadyClosedException::class => [409, 'REQUESTED_ACTION_CLOSED'],
            ConcurrentModificationException::class => [409, 'CONCURRENT_MODIFICATION'],
            // 422 — доменные ошибки валидации ввода
            RequestedActionDomainException::class => [422, 'REQUESTED_ACTION_INVALID'],
        ];

        foreach ($requestedActionErrors as $exceptionClass => [$status, $code]) {
            $exceptions->render(function (Throwable $e) use ($exceptionClass, $status, $code) {
                if (! $e instanceof $exceptionClass) {
                    return null;
                }

                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $code,
                ], $status);
            });
        }
    })->create();
