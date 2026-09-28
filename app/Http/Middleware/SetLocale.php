<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->header('Accept-Language')
            ?? $request->input('locale')
            ?? config('app.locale');

        // Приводим к формату "ru", "en"
        $locale = substr($locale, 0, 2);

        if (in_array($locale, config('app.locales', ['ru', 'en']))) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
