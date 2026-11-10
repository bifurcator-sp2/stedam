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
    })->create();
