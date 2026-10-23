<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use \App\Http\Controllers\Api\UserDataController;

/*Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');*/


Route::post('/register', [\App\Http\Controllers\Auth\RegisteredUserController::class, 'store']);
Route::post('/login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'store']);
Route::post('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetLinkController::class, 'store']);
Route::post('/reset-password', [\App\Http\Controllers\Auth\NewPasswordController::class, 'store']);



Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'destroy']);
    Route::get('/user', function (Request $request) {
        return $request->user()->toAuthArray();
    });
    Route::get('/roles', [\App\Http\Controllers\Api\RoleController::class, 'index']);
    Route::post('/set-user-roles', [\App\Http\Controllers\Auth\RegisteredUserController::class, 'setPublicRoles']);


    Route::get('/user-data', [UserDataController::class, 'show']);
    Route::post('/user-data', [UserDataController::class, 'store']);
    Route::put('/user-data', [UserDataController::class, 'update']);
    Route::patch('/user-data', [UserDataController::class, 'update']);
    Route::delete('/user-data', [UserDataController::class, 'destroy']);

    Route::get('/taxonomies/tree', [\App\Http\Controllers\Api\TaxonomyController::class, 'tree']);

    Route::get('/blocks-index', \App\Http\Controllers\Api\BlocksIndexController::class);

    // --- Блоки и типы ---
    Route::get('/block-types/struct', [\App\Http\Controllers\Api\BlockTypeController::class, 'struct']);
    Route::get('/block-types', [\App\Http\Controllers\Api\BlockTypeController::class, 'index']);


    Route::apiResource('blocks', \App\Http\Controllers\Api\BlockController::class);
    Route::get('/block/template/{code}', [\App\Http\Controllers\Api\BlockController::class, 'template']);

// --- Уроки ---

// 1. Статические GET — строго до /lessons/{lesson}
    Route::get('/lessons/learn',  [\App\Http\Controllers\Api\LessonController::class, 'list_learn']);
    Route::get('/lessons/filter', [\App\Http\Controllers\Api\LessonController::class, 'listFilterable']);

// 2. Статический POST (не конфликтует по методу, но пусть будет рядом)
    Route::post('/lessons/make',  [\App\Http\Controllers\Api\LessonController::class, 'make']);

// 3. Список
    Route::get('/lessons',        [\App\Http\Controllers\Api\LessonController::class, 'list']);

// 4. Динамические — здесь и только здесь {lesson}
    Route::get('/lessons/{lesson}',         [\App\Http\Controllers\Api\LessonController::class, 'show']);
    Route::post('/lessons/{lesson}/blocks', [\App\Http\Controllers\Api\LessonController::class, 'storeBlock']);
    Route::put('/lessons/{lesson}',         [\App\Http\Controllers\Api\LessonController::class, 'save']);
});

Route::middleware('auth:sanctum')->prefix('files')->group(function () {
    Route::post('/preload/{modelName}/{modelId}', [\App\Http\Controllers\FilesController::class, 'preload'])
        ->middleware('throttle:files-upload');

    Route::get('/preload/{modelName}/{modelId}', [\App\Http\Controllers\FilesController::class, 'list']);
    Route::delete('/preload/{modelName}/{modelId}', [\App\Http\Controllers\FilesController::class, 'deleteTemp']);
});


Route::get('/countries', [\App\Http\Controllers\Api\CountryController::class, 'index']);
Route::get('/countries/{country}', [\App\Http\Controllers\Api\CountryController::class, 'show']);
