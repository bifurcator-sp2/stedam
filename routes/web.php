<?php

use Illuminate\Support\Facades\Route;


Route::get('/app/{any?}', function () {
    $path = public_path('app/index.html');

    if (!file_exists($path)) {
        abort(404, 'Nuxt build not found');
    }

    return response()->file($path);
})
    ->where('any', '.*')
    ->name('nuxt.fallback');

// остальные маршруты ниже

//die(url()->full());


Route::get('/', function () {
    return view('welcome');
});

/*Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated 2.'], 401);
})->name('login');*/


