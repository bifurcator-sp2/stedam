<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ImpersonateFromHeader;
use App\Models\User;
use App\Services\Impersonation\ImpersonationTokenStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP-слой «просмотр от лица пользователя» (impersonation).
 *
 * ============================================================
 *  КАК РАБОТАЕТ «КНОПКА» NUXT4 SPA (КОНЕЦ В КОНЕЦ)
 * ============================================================
 *
 *  ┌─────────── вкладка A (админ) ───────────┐
 *  │ нажимает «Смотреть как X»               │
 *  │   ↓ POST /admin/impersonate/start       │
 *  │   ← { token, accept_url }               │
 *  │   window.open(accept_url, '_blank')     │
 *  └─────────────────────────────────────────┘
 *                                              ┌──── вкладка B (X) ────┐
 *                                              │ GET /app/impersonate? │
 *                                              │   token=<handoff>     │
 *                                              │ ↓ POST /admin/        │
 *                                              │   impersonate/accept  │
 *                                              │ ← { token:<session>,  │
 *                                              │     user:target }     │
 *                                              │ sessionStorage[`imp`] │
 *                                              │ = <session>           │
 *                                              │ все запросы несут     │
 *                                              │ X-Impersonate-Token   │
 *                                              └───────────────────────┘
 *
 * Почему именно handoff + session, а не «просто перелогин в cookie»:
 * Sanctum у нас stateful — cookie одна на домен. Перелогин в вкладке B
 * разлогинил бы админа в A. Поэтому:
 *   - админская cookie остаётся;
 *   - X-Impersonate-Token — маркер «эта вкладка смотрит как target»;
 *   - middleware подменяет пользователя только по этому заголовку.
 *
 * ============================================================
 *  ЭНДПОИНТЫ
 * ============================================================
 *
 *  start   — под auth (cookie админа). Только для роли admin.
 *            Возвращает одноразовый handoff-токен и URL для новой вкладки.
 *
 *  accept  — под auth (cookie админа, потому что target-а ещё «нет» в
 *            контексте — мы не знаем кто он, пока не обменяли токен).
 *            Обменивает handoff→session. Возвращает `toAuthArray()` target,
 *            чтобы вкладка сразу отрисовала UI от имени target-а.
 *
 *  status  — под auth. Возвращает инфу о текущей смотровой сессии.
 *            Нужен плашке в Nuxt: «Смотр от лица X — вернуться».
 *
 *  stop    — под auth (cookie админа). Гасит session-токен.
 *            Срабатывает «Выйти из просмотра» во вкладке B.
 *
 * ============================================================
 *  ПОЧЕМУ ОТДЕЛЬНО ОТ MIDDLEWARE?
 * ============================================================
 *
 * middleware ImpersonateFromHeader для не-GET запросов возвращает
 * фейково-успешный ответ и НЕ пускает дальше. Если бы accept/stop были
 * обычными POST-запросами «из вкладки B», middleware их съел бы.
 * Поэтому:
 *   - ВСЕ эндпоинты имперсонации регистрируются БЕЗ middleware
 *     ImpersonateFromHeader (см. routes/api.php, отдельная группа);
 *   - accept/stop используют handoff/session-токен как основную «ручку»,
 *     а cookie админа — как фактор подтверждения.
 */
class ImpersonationController extends Controller
{
    public function __construct(
        private readonly ImpersonationTokenStore $tokens,
    ) {}

    /**
     * POST /admin/impersonate/start  { user_id: int }
     *
     * Выдаёт одноразовый токен «перехода» в новую вкладку.
     *
     * Контракт (для Nuxt):
     *   response.accept_url — открывать через window.open(accept_url, '_blank').
     *   response.token      — тот же handoff, если Nuxt хочет сам собрать URL.
     *
     * Гарантии:
     *   - только role=admin (иначе 403);
     *   - нельзя «смотреть как админ» (иначе 422) — защита от эскалации
     *     и от рекурсивных просмотров;
     *   - нельзя смотреть как себя (422).
     */
    public function start(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $actor->hasRole('admin')) {
            abort(403, 'Forbidden');
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $targetId = (int) $data['user_id'];

        if ($targetId === (int) $actor->id) {
            return response()->json([
                'message' => 'Нельзя смотреть от лица самого себя.',
                'code'    => 'impersonation_self',
            ], 422);
        }

        /** @var User|null $target */
        $target = User::find($targetId);

        // Запрет смотреть «как админ»: иначе в режиме просмотра можно
        // вернуться в админку/Filament от чужого имени.
        if ($target !== null && $target->hasRole('admin')) {
            return response()->json([
                'message' => 'Нельзя смотреть от лица другого администратора.',
                'code'    => 'impersonation_admin_target',
            ], 422);
        }

        $handoff = $this->tokens->issueHandoff((int) $actor->id, $targetId);

        // URL новой вкладки: страница Nuxt, которая сделает accept.
        // База — фронт (SPA), поэтому хост берём из конфига фронта.
        $acceptUrl = rtrim((string) config('app.frontend_url'), '/')
            .'/impersonate?token='.urlencode($handoff);

        return response()->json([
            'token'      => $handoff,
            'accept_url' => $acceptUrl,
            'target'     => [
                'id'    => $target?->id,
                'name'  => $target?->name,
                'email' => $target?->email,
            ],
            'expires_in' => ImpersonationTokenStore::HANDOFF_TTL_MINUTES * 60,
        ]);
    }

    /**
     * POST /admin/impersonate/accept  { token: string }
     *
     * Обмен handoff → session. Вызывается ИЗ вкладки B.
     *
     * Почему под auth (cookie админа): cookie одинакова для всех вкладок
     * домена, поэтому у вкладки B она есть. Это даёт проверку, что аккаунт
     * админа ещё жив и не сменил роль.
     */
    public function accept(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $actor->hasRole('admin')) {
            abort(403, 'Forbidden');
        }

        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
        ]);

        $payload = $this->tokens->consumeHandoff((string) $data['token']);

        if ($payload === null) {
            return response()->json([
                'message' => 'Токен просмотра истёк или уже использован. Откройте вкладку заново.',
                'code'    => 'impersonation_handoff_invalid',
            ], 422);
        }

        // Handoff должен принадлежать ТОМУ ЖЕ админу, у которого cookie.
        if ((int) $payload['actor_id'] !== (int) $actor->id) {
            return response()->json([
                'message' => 'Токен выдан другому администратору.',
                'code'    => 'impersonation_actor_mismatch',
            ], 403);
        }

        /** @var User|null $target */
        $target = User::find($payload['target_id']);

        if ($target === null) {
            return response()->json([
                'message' => 'Пользователь для просмотра не найден.',
                'code'    => 'impersonation_target_missing',
            ], 404);
        }

        $sessionToken = $this->tokens->issueSession((int) $actor->id, (int) $target->id);

        // Возвращаем toAuthArray() target-а — вкладка B сразу поднимется
        // от его имени (как при /api/user), без дополнительного запроса.
        return response()->json([
            'token'      => $sessionToken,
            'header'     => ImpersonateFromHeader::HEADER,
            'user'       => $target->toAuthArray(),
            'actor'      => [
                'id'   => $actor->id,
                'name' => $actor->name,
            ],
            'read_only'  => true, // в смотровом режиме записи фейковые
            'expires_in' => ImpersonationTokenStore::SESSION_TTL_MINUTES * 60,
        ]);
    }

    /**
     * GET /admin/impersonate/status
     *
     * Инфа о текущей смотровой сессии — для «плашки» в Nuxt.
     * Отвечает { impersonating: bool, ... }.
     */
    public function status(Request $request): JsonResponse
    {
        $token = (string) $request->header(ImpersonateFromHeader::HEADER, '');
        $payload = $this->tokens->peek($token);

        if ($payload === null) {
            return response()->json(['impersonating' => false]);
        }

        /** @var User|null $target */
        $target = User::find($payload['target_id']);
        /** @var User|null $actor */
        $actor = User::find($payload['actor_id']);

        return response()->json([
            'impersonating' => true,
            'actor'         => $actor !== null
                ? ['id' => $actor->id, 'name' => $actor->name]
                : null,
            'target'        => $target !== null
                ? ['id' => $target->id, 'name' => $target->name, 'email' => $target->email]
                : null,
            'expires_in'    => max(0, ($payload['created_at'] + ImpersonationTokenStore::SESSION_TTL_MINUTES * 60) - now()->getTimestamp()),
        ]);
    }

    /**
     * POST /admin/impersonate/stop
     *
     * «Выйти из просмотра». Гасит session-токен во вкладке B.
     * Cookie админа не трогается — вкладка A остаётся под админом.
     */
    public function stop(Request $request): JsonResponse
    {
        $token = (string) $request->header(ImpersonateFromHeader::HEADER, '');

        if ($token !== '') {
            $this->tokens->forgetSession($token);
        }

        return response()->json(['message' => 'Просмотр завершён.']);
    }
}
