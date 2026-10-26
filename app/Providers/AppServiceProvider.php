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
        // Здесь ничего не нужно.
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
