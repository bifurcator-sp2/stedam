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


    Route::get('/block-types', [\App\Http\Controllers\Api\BlockTypeController::class, 'index']);
    Route::get('/block-types/{code}', [\App\Http\Controllers\Api\BlockTypeController::class, 'show']);

    Route::apiResource('blocks', \App\Http\Controllers\Api\BlockController::class);

});

Route::middleware('auth:sanctum')->prefix('files')->group(function () {
    Route::post('/preload/{modelName}/{modelId}', [\App\Http\Controllers\FilesController::class, 'preload'])
        ->middleware('throttle:files-upload');

    Route::get('/preload/{modelName}/{modelId}', [\App\Http\Controllers\FilesController::class, 'list']);
    Route::delete('/preload/{modelName}/{modelId}', [\App\Http\Controllers\FilesController::class, 'deleteTemp']);
});


Route::get('/countries', [\App\Http\Controllers\Api\CountryController::class, 'index']);
Route::get('/countries/{country}', [\App\Http\Controllers\Api\CountryController::class, 'show']);
