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
});

Route::get('/countries', [\App\Http\Controllers\Api\CountryController::class, 'index']);
Route::get('/countries/{country}', [\App\Http\Controllers\Api\CountryController::class, 'show']);
