<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \App\Http\Middleware\SetLocale::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Application-исключения контекста Learn → HTTP.
        $exceptions->render(function (\App\Domain\Learn\Exceptions\LearnNotFound $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        });
        $exceptions->render(function (\App\Application\Learn\Exceptions\LessonNotFound $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        });
        $exceptions->render(function (\App\Application\Learn\Exceptions\LearnAccessDenied $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        });
        $exceptions->render(function (\App\Domain\Learn\Exceptions\InvalidStatusTransition $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
        $exceptions->render(function (\App\Domain\Learn\Exceptions\LearnAlreadyGraded $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        // Исключения контекста Courses → HTTP (docs/course-api-spec.md §0.5).
        // Формат: {message, code, ...extra}. code — машиночитаемый идентификатор,
        // чтобы фронт не парсил текст сообщения.
        $courseErrors = [
            // 403
            \App\Application\Course\Exceptions\CourseAccessDenied::class => [403, 'course_access_denied'],
            // 404
            \App\Application\Course\Exceptions\CourseNotFound::class => [404, 'course_not_found'],
            \App\Domain\Course\Exceptions\CourseNotFound::class => [404, 'course_not_found'],
            \App\Domain\Course\Exceptions\CourseThemeNotFound::class => [404, 'course_theme_not_found'],
            \App\Domain\Course\Exceptions\CourseLessonNotInTheme::class => [404, 'course_lesson_not_in_theme'],
            // 409
            \App\Domain\Course\Exceptions\CourseAlreadyAssigned::class => [409, 'course_already_assigned'],
            \App\Domain\Course\Exceptions\CourseLessonAlreadyInCourse::class => [409, 'course_lesson_already_in_course'],
            // 422
            \App\Domain\Course\Exceptions\CourseLocaleChangeDenied::class => [422, 'course_locale_change_denied'],
            \App\Domain\Course\Exceptions\CourseTaxonomyChangeDenied::class => [422, 'course_taxonomy_change_denied'],
            \App\Domain\Course\Exceptions\InvalidCourseOrder::class => [422, 'invalid_course_order'],
            \App\Domain\Course\Exceptions\InvalidCourseGrade::class => [422, 'invalid_course_grade'],
            \App\Domain\Course\Exceptions\InvalidCompletion::class => [422, 'invalid_completion'],
            \App\Domain\Course\Exceptions\InvalidNextLessonThreshold::class => [422, 'invalid_next_lesson_threshold'],
            \App\Domain\Course\Exceptions\InvalidTimeGap::class => [422, 'invalid_time_gap'],
            // VO-исключения: голый InvalidArgumentException давал бы 500.
            \App\Domain\Course\Exceptions\InvalidLocale::class => [422, 'invalid_locale'],
            \App\Domain\Course\Exceptions\InvalidCourseTitle::class => [422, 'invalid_course_title'],
        ];

        foreach ($courseErrors as $exceptionClass => [$status, $code]) {
            $exceptions->render(function (\Throwable $e) use ($exceptionClass, $status, $code) {
                if (!$e instanceof $exceptionClass) {
                    return null;
                }

                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $code,
                ], $status);
            });
        }
    })->create();
