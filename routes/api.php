<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use \App\Http\Controllers\Api\UserDataController;


// Запрос кода на почту
Route::post('/auth/request-code', function (Illuminate\Http\Request $request) {
    $request->validate(['email' => 'required|email']);

    $user = \App\Models\User::where('email', $request->email)->first();

    // Если пользователь не найден, создаем временный экземпляр (в памяти, без сохранения в БД)
    if (!$user) {
        $user = new \App\Models\User([
            'email' => $request->email,
        ]);
    }

    // Генерируем 6-значный код
    $code = rand(100000, 999999);

    // Сохраняем в Redis на 15 минут
    \Illuminate\Support\Facades\Cache::put("login_code_{$user->email}", (string)$code, now()->addMinutes(15));

    // Отправляем письмо (метод notify работает и для несохраненных моделей)
    $user->notify(new \App\Notifications\LoginCodeNotification($code));

    return response()->json(['message' => 'Код отправлен.']);
})->middleware('throttle:6,1'); // Защита от спама (6 попыток в минуту)

// Проверка кода и вход
Route::post('/auth/login-with-code', function (Illuminate\Http\Request $request) {
    $request->validate([
        'email' => 'required|email',
        'code' => 'required|string'
    ]);

    // 1. Сначала проверяем код в кэше
    $cachedCode = \Illuminate\Support\Facades\Cache::get("login_code_{$request->email}");

    if (!$cachedCode || $cachedCode !== $request->code) {
        abort(422, 'Неверный или истекший код.');
    }

    // 2. Код верный, сразу удаляем его, чтобы нельзя было использовать повторно
    \Illuminate\Support\Facades\Cache::forget("login_code_{$request->email}");

    // 3. Ищем пользователя
    $user = \App\Models\User::where('email', $request->email)->first();

    // 4. Если пользователя нет — создаем его в базе
    if (!$user) {
        $user = \App\Models\User::create([
            'email' => $request->email,
            'name' => explode('@', $request->email)[0],
            'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(16)),
        ]);
        $user->markEmailAsVerified();
    }

    // 5. Логиним пользователя (Laravel Sanctum выдаст сессию)
    \Illuminate\Support\Facades\Auth::login($user);
    $request->session()->regenerate();

    return response()->json(['message' => 'Успешный вход']);
});

Route::post('/register', [\App\Http\Controllers\Auth\RegisteredUserController::class, 'store']);
Route::post('/login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'store']);
Route::post('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetLinkController::class, 'store']);
Route::post('/reset-password', [\App\Http\Controllers\Auth\NewPasswordController::class, 'store']);
// Ссылка из письма — БЕЗ auth (юзер может быть на другом устройстве)
Route::get('/email/verify/{id}/{hash}', function (Illuminate\Http\Request $request) {
    $user = \App\Models\User::findOrFail($request->route('id'));

    abort_unless(
        hash_equals(sha1($user->getEmailForVerification()), (string)$request->route('hash')),
        403
    );

    if (!$user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new \Illuminate\Auth\Events\Verified($user));
    }

    return redirect(
        rtrim(env('APP_URL'), '/') . '/app/verify-email?verified=1'
    );
})->middleware('signed')->name('verification.verify.api');

// Публичная проверка устройства (доступна без авторизации)
Route::get('/devices/{deviceId}/check', [\App\Http\Controllers\Api\DeviceAccessController::class, 'check']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'destroy']);
    Route::get('/user', function (Request $request) {
        $user = $request->user();
        $data = $user->toAuthArray();

        // Читаем ID устройства из заголовка, который пришлет фронтенд
        $deviceId = $request->header('X-Device-Id');

        // Проверяем в белом списке (если заголовка нет — возвращаем false)
        $data['allow_password_auth'] = $deviceId
            ? \App\Models\AllowedDevice::where('device_id', $deviceId)->exists()
            : false;

        return $data;
    })->middleware('throttle:user-check');

    Route::post('/email/verification-notification', function (Request $request) {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }
        $request->user()->sendEmailVerificationNotification();
        return response()->json(['message' => 'Verification email sent.']);
    })->middleware('throttle:verification-email');


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
    Route::get('/lessons/learn', [\App\Http\Controllers\Api\LearnController::class, 'list_learn']);
    Route::get('/lessons/actual', [\App\Http\Controllers\Api\LearnController::class, 'getActualLessons']);
    Route::post('/learn/begin', [LearnController::class, 'beginLearnLesson']);

    Route::get('/lessons/filter', [\App\Http\Controllers\Api\LessonController::class, 'listFilterable']);

// 2. Статический POST (не конфликтует по методу, но пусть будет рядом)
    Route::post('/lessons/make', [\App\Http\Controllers\Api\LessonController::class, 'make']);

// 3. Список
    Route::get('/lessons', [\App\Http\Controllers\Api\LessonController::class, 'list']);

// 4. Динамические — здесь и только здесь {lesson}
    Route::get('/lessons/{lesson}', [\App\Http\Controllers\Api\LessonController::class, 'show']);
    Route::post('/lessons/{lesson}/blocks', [\App\Http\Controllers\Api\LessonController::class, 'storeBlock']);
    Route::put('/lessons/{lesson}', [\App\Http\Controllers\Api\LessonController::class, 'save']);

    Route::get('/teacher-tests', [\App\Http\Controllers\Api\TestsController::class, 'list']);
    Route::get('/teacher-tests/templates', [\App\Http\Controllers\Api\TestsController::class, 'templates']);
    Route::apiResource('teacher-test', \App\Http\Controllers\Api\TestsController::class)
        ->parameters(['teacher-test' => 'lessonBlock'])
        ->only(['store', 'show', 'update', 'destroy']);

    Route::get('/admin/devices', [\App\Http\Controllers\Api\DeviceAccessController::class, 'index']);
    Route::post('/admin/devices/{deviceId}', [\App\Http\Controllers\Api\DeviceAccessController::class, 'store']);
    Route::delete('/admin/devices/{deviceId}', [\App\Http\Controllers\Api\DeviceAccessController::class, 'destroy']);


});

Route::middleware('auth:sanctum')->prefix('files')->group(function () {
    Route::post('/preload/{modelName}/{modelId}', [\App\Http\Controllers\FilesController::class, 'preload'])
        ->middleware('throttle:files-upload');

    Route::get('/preload/{modelName}/{modelId}', [\App\Http\Controllers\FilesController::class, 'list']);
    Route::delete('/preload/{modelName}/{modelId}', [\App\Http\Controllers\FilesController::class, 'deleteTemp']);
});


Route::get('/countries', [\App\Http\Controllers\Api\CountryController::class, 'index']);
Route::get('/countries/{country}', [\App\Http\Controllers\Api\CountryController::class, 'show']);
