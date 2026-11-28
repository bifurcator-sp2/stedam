<?php

use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\BlocksIndexController;
use App\Http\Controllers\Api\BlockTypeController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\CoursesController;
use App\Http\Controllers\Api\DeviceAccessController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\LearnController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\PushSubscriptionsController;
use App\Http\Controllers\Api\RequestedActionsController;
use App\Http\Controllers\Api\ResolversController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TaskForLearnController;
use App\Http\Controllers\Api\TaskGenerateController;
use App\Http\Controllers\Api\TaskResolversCatalogController;
use App\Http\Controllers\Api\TaxonomyController;
use App\Http\Controllers\Api\TestsController;
use App\Http\Controllers\Api\UserDataController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\FilesController;
use App\Models\AllowedDevice;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Запрос кода на почту
Route::post('/auth/request-code', function (Request $request) {
    $request->validate(['email' => 'required|email']);

    $user = User::where('email', $request->email)->first();

    // Если пользователь не найден, создаем временный экземпляр (в памяти, без сохранения в БД)
    if (! $user) {
        $user = new User([
            'email' => $request->email,
        ]);
    }

    // Генерируем 6-значный код
    $code = rand(100000, 999999);

    // Сохраняем в Redis на 15 минут
    Cache::put("login_code_{$user->email}", (string) $code, now()->addMinutes(15));

    // Отправляем письмо (метод notify работает и для несохраненных моделей)
    $user->notify(new LoginCodeNotification($code));

    return response()->json(['message' => 'Код отправлен.']);
})->middleware('throttle:6,1'); // Защита от спама (6 попыток в минуту)

// Проверка кода и вход
Route::post('/auth/login-with-code', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'code' => 'required|string',
    ]);

    // 1. Сначала проверяем код в кэше
    $cachedCode = Cache::get("login_code_{$request->email}");

    if (! $cachedCode || $cachedCode !== $request->code) {
        abort(422, 'Неверный или истекший код.');
    }

    // 2. Код верный, сразу удаляем его, чтобы нельзя было использовать повторно
    Cache::forget("login_code_{$request->email}");

    // 3. Ищем пользователя
    $user = User::where('email', $request->email)->first();

    // 4. Если пользователя нет — создаем его в базе
    if (! $user) {
        $user = User::create([
            'email' => $request->email,
            'name' => explode('@', $request->email)[0],
            'password' => Hash::make(Str::random(16)),
        ]);
        $user->markEmailAsVerified();
    }

    // 5. Логиним пользователя (Laravel Sanctum выдаст сессию)
    Auth::login($user);
    $request->session()->regenerate();

    return response()->json(['message' => 'Успешный вход']);
});

Route::post('/register', [RegisteredUserController::class, 'store']);
Route::post('/login', [AuthenticatedSessionController::class, 'store']);
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store']);
Route::post('/reset-password', [NewPasswordController::class, 'store']);
// Ссылка из письма — БЕЗ auth (юзер может быть на другом устройстве)
Route::get('/email/verify/{id}/{hash}', function (Request $request) {
    $user = User::findOrFail($request->route('id'));

    abort_unless(
        hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash')),
        403
    );

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new Verified($user));
    }

    return redirect(
        rtrim(env('APP_URL'), '/').'/app/verify-email?verified=1'
    );
})->middleware('signed')->name('verification.verify.api');

// Публичная проверка устройства (доступна без авторизации)
Route::get('/devices/{deviceId}/check', [DeviceAccessController::class, 'check']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    Route::get('/user', function (Request $request) {
        $user = $request->user();
        $data = $user->toAuthArray();

        // Читаем ID устройства из заголовка, который пришлет фронтенд
        $deviceId = $request->header('X-Device-Id');

        // Проверяем в белом списке (если заголовка нет — возвращаем false)
        $data['allow_password_auth'] = $deviceId
            ? AllowedDevice::where('device_id', $deviceId)->exists()
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

    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/set-user-roles', [RegisteredUserController::class, 'setPublicRoles']);

    Route::get('/user-data', [UserDataController::class, 'show']);
    Route::post('/user-data', [UserDataController::class, 'store']);
    Route::put('/user-data', [UserDataController::class, 'update']);
    Route::patch('/user-data', [UserDataController::class, 'update']);
    Route::delete('/user-data', [UserDataController::class, 'destroy']);

    Route::get('/taxonomies/tree', [TaxonomyController::class, 'tree']);

    Route::get('/blocks-index', BlocksIndexController::class);

    // --- Блоки и типы ---
    Route::get('/block-types/struct', [BlockTypeController::class, 'struct']);
    Route::get('/block-types', [BlockTypeController::class, 'index']);

    Route::apiResource('blocks', BlockController::class);
    Route::get('/block/template/{code}', [BlockController::class, 'template']);

    // --- Уроки ---

    // 1. Статические GET — строго до /lessons/{lesson}
    Route::get('/lessons/types', [LessonController::class, 'types']);
    Route::get('/lessons/learn', [LearnController::class, 'list_learn']);

    Route::get('/lessons/actual', [LearnController::class, 'getActualLessons']);
    Route::post('/learn/begin', [LearnController::class, 'beginLearnLesson']);
    Route::patch('/learn/save', [LearnController::class, 'saveLearn']);
    Route::get('/learn/snapshot/{learn}', [LearnController::class, 'snapshot'])->whereNumber('learn');
    Route::post('/learns/{learn}/done', [LearnController::class, 'markDone'])->whereNumber('learn');

    Route::get('/learn/answers/upload-config', [LearnController::class, 'answerUploadConfig']);

    // Прохождения: чтение и действия (контекстные).
    Route::get('/teacher/learns', [LearnController::class, 'teacherLearns']);
    Route::get('/teacher/students', [LearnController::class, 'teacherStudents']);
    Route::get('/parent/children', [LearnController::class, 'parentChildren']);
    Route::get('/parent/learns', [LearnController::class, 'parentLearns']);
    Route::post('/learns/schedule', [LearnController::class, 'schedule']);
    Route::get('/learns/{learn}', [LearnController::class, 'show'])->whereNumber('learn');
    Route::patch('/learns/{learn}/answers', [LearnController::class, 'saveAnswers'])->whereNumber('learn');

    Route::post('/learns/{learn}/grade', [LearnController::class, 'grade'])->whereNumber('learn');
    Route::post('/learns/{learn}/cancel', [LearnController::class, 'cancel'])->whereNumber('learn');

    // 2. Статический POST (не конфликтует по методу, но пусть будет рядом)
    Route::post('/lessons/make', [LessonController::class, 'make']);

    // 3. Список
    Route::get('/lessons', [LessonController::class, 'list']);

    // 4. Динамические — здесь и только здесь {lesson}
    Route::get('/lessons/{lesson}', [LessonController::class, 'show']);
    Route::post('/lessons/{lesson}/blocks', [LessonController::class, 'storeBlock']);
    Route::put('/lessons/{lesson}', [LessonController::class, 'save']);

    Route::get('/teacher-tests', [TestsController::class, 'list']);
    Route::get('/tests-proc', [TestsController::class, 'list_proc']);
    Route::get('/teacher-tests/templates', [TestsController::class, 'templates']);
    Route::apiResource('teacher-test', TestsController::class)
        ->parameters(['teacher-test' => 'lessonBlock'])
        ->only(['store', 'show', 'update', 'destroy']);

    Route::get('/admin/devices', [DeviceAccessController::class, 'index']);
    Route::post('/admin/devices/{deviceId}', [DeviceAccessController::class, 'store']);
    Route::delete('/admin/devices/{deviceId}', [DeviceAccessController::class, 'destroy']);

    Route::get('/tasks/catalog', TaskResolversCatalogController::class)
        ->name('api.catalog');

    Route::get('/tasks/resolvers', ResolversController::class)
        ->name('api.resolvers');

    Route::get('/tasks/forlearn', TaskForLearnController::class)
        ->name('api.tasks.forlearn');

    Route::get('/tasks/random', TaskGenerateController::class)
        ->name('api.tasks.random');

});

Route::middleware('auth:sanctum')->prefix('files')->group(function () {
    Route::post('/preload/{modelName}/{modelId}', [FilesController::class, 'preload'])
        ->middleware('throttle:files-upload');

    Route::get('/preload/{modelName}/{modelId}', [FilesController::class, 'list']);
    Route::delete('/preload/{modelName}/{modelId}', [FilesController::class, 'deleteTemp']);
});

// --- Community: социальный граф (связи между пользователями) ---
Route::middleware('auth:sanctum')->group(function () {
    // Типы связей текущего пользователя с количеством по каждому.
    Route::get('/my-relations', [CommunityController::class, 'myRelations'])
        ->name('api.my-relations');

    // Пользователи из связей: ?relation=teacher&counterpart_role=student.
    Route::get('/users', [CommunityController::class, 'users'])
        ->name('api.users');
});

// --- RequestedActions (раздел 1 «http слой», 2.13) ---
Route::middleware('auth:sanctum')->group(function () {
    $ra = RequestedActionsController::class;
    Route::get('/requested-actions', [$ra, 'index'])->name('api.request-actions.index');
    Route::get('/request-action/form', [$ra, 'form'])->name('api.request-action.form');
    Route::post('/request-action/request', [$ra, 'request'])->name('api.request-action.request');
    Route::get('/approve-action/form', [$ra, 'approvalForm'])->name('api.approve-action.form');
    Route::post('/approve-action/approve', [$ra, 'approve'])->name('api.approve-action.approve');
});

Route::get('/countries', [CountryController::class, 'index']);
Route::get('/countries/{country}', [CountryController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Impersonation («Смотреть как пользователь»)
|--------------------------------------------------------------------------
|
| Сценарий (подробно — в App\Http\Controllers\Api\ImpersonationController):
|
|   1. Вкладка A (админ): POST /admin/impersonate/start {user_id}
|        → {token: <handoff>, accept_url}
|   2. Nuxt: window.open(accept_url, '_blank') — открывает вторую вкладку B
|        на /impersonate?token=<handoff>
|   3. Вкладка B: POST /admin/impersonate/accept {token: <handoff>}
|        → {token: <session>, user: target, read_only: true}
|      Nuxt кладёт <session> в sessionStorage вкладки B (НЕ в cookie!)
|   4. Все запросы вкладки B несут X-Impersonate-Token: <session>
|      middleware ImpersonateFromHeader подменяет пользователя на target.
|
| ВАЖНО: эти роуты идут БЕЗ middleware ImpersonateFromHeader — иначе
| accept/stop (POST из вкладки B) были бы перехвачены «фейковым write».
| Подмену пользователя middleware делает уже на остальных маршрутах.
|
*/
Route::middleware('auth:sanctum')->prefix('admin/impersonate')->group(function () {
    $impersonation = ImpersonationController::class;

    // Начать просмотр — вернёт одноразовый handoff + URL для новой вкладки.
    Route::post('/start', [$impersonation, 'start']);
    // Обмен handoff → session во вкладке B.
    Route::post('/accept', [$impersonation, 'accept']);
    // Статус текущей смотровой сессии (для плашки в UI).
    Route::get('/status', [$impersonation, 'status']);
    // Завершить просмотр (кнопка «Вернуться»).
    Route::post('/stop', [$impersonation, 'stop']);
});

// --- Курсы (контракт: docs/course-api-spec.md) ---
Route::middleware('auth:sanctum')->prefix('courses')->group(function () {
    $c = CoursesController::class;

    // Курс
    Route::get('/', [$c, 'index']);
    Route::post('/', [$c, 'store']);
    Route::get('/{courseId}', [$c, 'show'])->whereNumber('courseId');
    Route::match(['put', 'patch'], '/{courseId}', [$c, 'update'])->whereNumber('courseId');
    Route::delete('/{courseId}', [$c, 'destroy'])->whereNumber('courseId');

    // Назначения
    Route::get('/{courseId}/assignments', [$c, 'assignments'])->whereNumber('courseId');
    Route::post('/{courseId}/assignments', [$c, 'assign'])->whereNumber('courseId');
    Route::delete('/{courseId}/assignments/{studentId}', [$c, 'unassign'])
        ->whereNumber('courseId')->whereNumber('studentId');

    // Пересчёт
    Route::post('/{courseId}/assignments/{studentId}/recalculate', [$c, 'recalculate'])
        ->whereNumber('courseId')->whereNumber('studentId');
    Route::post('/{courseId}/recalculate', [$c, 'recalculateAll'])->whereNumber('courseId');
});
Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
    $c = NotificationsController::class;
    Route::get('/', [$c, 'index']);
    Route::patch('/', [$c, 'setRead']);
});

// --- Браузерные push (WebPush / VAPID), контракт: docs/browser-push-spec.md ---
Route::get('/push/vapid-public-key', [PushSubscriptionsController::class, 'vapidPublicKey']);
Route::middleware('auth:sanctum')->prefix('push')->group(function () {
    $c = PushSubscriptionsController::class;
    Route::post('/subscriptions', [$c, 'store'])->middleware('throttle:30,1');
    Route::delete('/subscriptions', [$c, 'destroy']);
});
