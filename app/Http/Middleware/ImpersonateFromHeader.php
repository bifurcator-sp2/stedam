<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Impersonation\ImpersonationTokenStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Подмена пользователя запроса по заголовку X-Impersonate-Token.
 *
 * ============================================================
 *  КАК ЭТО РАБОТАЕТ (сквозной сценарий)
 * ============================================================
 *
 *  1. Админ в вкладке A жмёт «Смотреть как X».
 *     → POST /admin/impersonate/start {user_id: X}
 *     → сервер возвращает {token: <handoff>, url: "/app/impersonate?token=<handoff>"}.
 *
 *  2. Nuxt открывает url во ВКЛАДКЕ B (вторая вкладка, тот же проект):
 *     window.open(url, '_blank').
 *
 *  3. Вкладка B делает POST /admin/impersonate/accept {token: <handoff>}
 *     → сервер обменивает handoff на <session>-токен и возвращает
 *       {token: <session>, user: {... target ...}}.
 *     Nuxt кладёт <session>-токен в sessionStorage[этой вкладки].
 *
 *  4. Дальше каждый запрос вкладки B несёт заголовок:
 *         X-Impersonate-Token: <session>
 *     → СРАБАТЫВАЕТ ЭТОТ middleware.
 *
 * Что делает middleware для запросов с валидным токеном:
 *   - достаёт session-токен из Cache (ImpersonationTokenStore::findSession);
 *   - убеждается, что отправитель — тот же админ (cookie-сессия Sanctum жива
 *     и её user_id == payload.actor_id). Иначе токен игнорируется — простая
 *     утечка токена не даёт доступ, нужна ещё и cookie админа;
 *   - подменяет guard-пользователя на target: Auth::guard('web')->setUser($target).
 *     ВСЁ, что ниже по стеку (и throttle, и auth:sanctum, и контроллеры,
 *     и EloquentCourseContextResolver) использует $request->user() — поэтому
 *     подмена прозрачна для всего кода приложения;
 *   - для не-GET запросов (POST/PUT/PATCH/DELETE) возвращает «фейково-успешный»
 *     ответ и НЕ передаёт запрос дальше — в режиме просмотра ничего не пишется
 *     в БД (см. ниже «ПОЧЕМУ ТАК»).
 *
 * ============================================================
 *  ПОЧЕМУ «ФЕЙКОВЫЙ WRITE», А НЕ ГЛОБАЛЬНЫЙ ХУК В ELOQUENT
 * ============================================================
 *
 * Идея перехватить создание/изменение глобально в Eloquent (Model::creating,
 * Model::saving) и «делать вид, что сохранилось» — плохая:
 *   - не покрывает DB::table()->insert/update, массовые upsert;
 *   - придуманный id не совпадёт с реальным автоинкрементом (в PostgreSQL
 *     nextval не тратится впустую, а при rollback транзакции остаётся «дыра»)
 *     → следующие запросы GET /entity/{fakeId} вернут 404;
 *   - побочные эффекты (sendEmailVerificationNotification, ModelEvents
 *     created/updated, jobs) всё равно сработают; письмо уйдёт, данных нет.
 *
 * Поэтому фейк-ответы делает ОДНО место — этот middleware, и только для
 * смотровых запросов. Формат ответа ровно «как будто сохранилось»: id из
 * payload, остальные поля из тела запроса. Код статуса:
 *   - POST        → 201
 *   - PUT/PATCH   → 200
 *   - DELETE      → 204 (пустое тело)
 *   - и ответ помечен полем _impersonated: true (ФРОНТ может не показывать).
 *
 * ============================================================
 *  ПОРЯДОК ПОДКЛЮЧЕНИЯ (ВАЖНО!)
 * ============================================================
 *
 * Он должен идти ДО SetLocale и до throttle, то есть первым в api-стеке
 * (bootstrap/app.php: $middleware->api(prepend: [ImpersonateFromHeader::class, ...])).
 * Иначе лимиты (files-upload, user-check) будут считаться по id админа:
 * их `RateLimiter::for(...->by($request->user()->id))` получит ещё НЕ
 * подменённого пользователя. После этого middleware $request->user() —
 * это уже target, и лимиты считаются корректно.
 */
class ImpersonateFromHeader
{
    public function __construct(
        private readonly ImpersonationTokenStore $tokens,
    ) {}

    /**
     * Имя заголовка, который носит «смотровая» вкладка.
     * Держим в константе, чтобы Nuxt и бэк не разъехались.
     */
    public const HEADER = 'X-Impersonate-Token';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = (string) $request->header(self::HEADER, '');

        if ($rawToken === '') {
            // Обычный запрос — ничего не меняем.
            return $next($request);
        }

        $payload = $this->tokens->findSession($rawToken);

        if ($payload === null) {
            // Токен неизвестен/просрочен. Не подменяем пользователя и не
            // выдаём ошибку — просто отдаём специальный код, чтобы Nuxt
            // понял: смотровая сессия кончилась, надо переоткрыть вкладку
            // или выйти. Используем 419 (обычно «страница истекла»),
            // его Nuxt трактует как «сессия кончилась».
            return response()->json([
                'message' => 'Смотровая сессия истекла или недействительна.',
                'code'    => 'impersonation_expired',
            ], 419);
        }

        // Проверяем, что текущая cookie-сессия (Sanctum) — это админ,
        // который выдал токен. Без этого украденный токен бесполезен.
        $realUserId = (int) $request->user()?->getAuthIdentifier();
        if ($realUserId !== 0 && (int) $payload['actor_id'] !== $realUserId) {
            return response()->json([
                'message' => 'Смотровой токен выдан другому пользователю.',
                'code'    => 'impersonation_forbidden',
            ], 403);
        }

        /**
         * @var User|null $target
         * Кэш не нужен — модель тяжело кэшировать; один SELECT на запрос
         * не проблема. Если хотите — оберните Cache::remember.
         */
        $target = User::find($payload['target_id']);

        if ($target === null) {
            // Цель могла быть удалена — просмотр прекратился.
            $this->tokens->forgetSession($rawToken);

            return response()->json([
                'message' => 'Пользователь для просмотра не найден.',
                'code'    => 'impersonation_target_missing',
            ], 404);
        }

        // ---- ПОДМЕНА ПОЛЬЗОВАТЕЛЯ ----
        //
        // Пока guard ещё «web», Auth::guard('web')->setUser($target) + замена
        // resolver'а на запросе → $request->user() вернёт target во всех
        // контроллерах и в EloquentCourseContextResolver (там
        // $this->auth->guard()->user()).
        //
        // Auth::guard('web') а не 'sanctum': 'web' — наш session-guard (нужен
        // сразу, т.к. SetLocale и throttle идут раньше auth:sanctum);
        // auth:sanctum ниже по стеку при валидной cookie-сессии переиспользует
        // уже установленного пользователя.
        $webGuard = Auth::guard('web');
        $webGuard->setUser($target);

        // Дублируем в текущий (Default) guard — так $request->user()
        // и любой `auth()->user()` вернут target даже до auth:sanctum.
        $request->setUserResolver(static fn (?string $guard = null) => $target);

        // Помечаем запрос, чтобы ниже по стеку было видно «мы в просмотре».
        $request->attributes->set('impersonating', true);
        $request->attributes->set('impersonator_id', (int) $payload['actor_id']);
        $request->attributes->set('impersonated_user_id', (int) $payload['target_id']);
        $request->attributes->set('impersonation_token', $rawToken);

        // ---- ФЕЙКОВЫЙ WRITE ----
        //
        // Если запрос изменяющий — в режиме просмотра НЕ доходим до контроллера.
        // Ответ — «как будто всё сохранилось». См. блок «ПОЧЕМУ» выше.
        if (! $request->isMethodCacheable()) {
            return $this->fakeWriteResponse($request);
        }

        return $next($request);
    }

    /**
     * Ответ на изменяющий запрос в режиме просмотра.
     *
     * Правила:
     *   - DELETE → 204, тела нет;
     *   - POST   → 201 + тело запроса + id;
     *   - PUT/PATCH → 200 + тело запроса (id из тела, если есть).
     *
     * id придумываем из «следующего» автоинкремента — лучше из БД (чтобы
     * фронт не увидел очевидно повторяющийся 0/1), но не знаем таблицу.
     * Поэтому генерируем монотонный псевдо-id из времени: он «выглядит как
     * реальный id», а коллизия с настоящим id маловероятна и безвредна —
     * запись в БД всё равно не появится.
     * Если в теле передан id (update) — сохраняем его как есть.
     */
    private function fakeWriteResponse(Request $request): Response
    {
        if ($request->isMethod('DELETE')) {
            return response()->noContent(); // 204
        }

        $body = $request->all();

        // id для create: не трогаем, если уже есть в body.
        if (! array_key_exists('id', $body)) {
            $body['id'] = $this->fakeId();
        }

        $status = $request->isMethod('POST') ? 201 : 200;

        // Помечаем ответ, чтобы фронт мог отрисовать «демо-режим» (опционально).
        return response()->json(array_merge($body, [
            '_impersonated' => true,
            'message'       => 'Изменения не сохранены: режим просмотра.',
        ]), $status);
    }

    /**
     * Псевдо-id: миллисекунды * 1000 + случайный хвост.
     *
     * Такой id:
     *   - не 0/1 (не выглядит как рыба),
     *   - не совпадает с соседним create в той же вкладке,
     *   - укладывается в bigint.
     *
     * В БД он не пишется никогда (запрос до контроллера не доходит).
     */
    private function fakeId(): int
    {
        return (int) (now()->getPreciseTimestamp(3) * 1000 + random_int(0, 999));
    }
}
