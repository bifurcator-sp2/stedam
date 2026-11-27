<?php

declare(strict_types=1);

namespace App\Services\Impersonation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Хранилище токенов «просмотра от лица пользователя» (impersonation).
 *
 * ============================================================
 *  ЗАЧЕМ ЭТОТ КЛАСС
 * ============================================================
 *
 * Задача: администратор в одной вкладке Nuxt4-SPA нажимает «Смотреть как
 * пользователь X», и проект открывается В ДРУГОЙ вкладке уже под X, пока
 * первая вкладка остаётся под админом.
 *
 * Проблема с «обычной» impersonation на Sanctum:
 *   - у нас stateful Sanctum (cookie-сессия), cookie одна на все вкладки
 *     одного домена. Если «перелогиниться» под X — вкладка админа тоже
 *     потеряет админа. Поэтому глобальную сессию трогать НЕЛЬЗЯ.
 *
 * Решение: передать «право просмотра» через одноразовый токен в URL новой
 * вкладки, а дальше вкладка носит его в заголовке X-Impersonate-Token.
 * Cookie Sanctum (сессия админа) остаётся как есть — она подтверждает, кто
 * именно выдал токен, но пользователя в контексте запроса подменяет
 * middleware ImpersonateFromHeader по токену из заголовка.
 *
 * ============================================================
 *  ДВА ВИДА ТОКЕНОВ
 * ============================================================
 *
 * 1) HANDOFF (одноразовый, TTL ~60 сек)
 *    - выдаётся админом через POST /admin/impersonate/start;
 *    - кладётся в URL новой вкладки: /app/impersonate?token=...;
 *    - обменивается вкладкой на «сессионный» токен через
 *      POST /admin/impersonate/accept;
 *    - после обмена сразу удаляется из кэша (одноразовость).
 *
 * 2) SESSION (многоразовый, TTL ~30 мин)
 *    - живёт во вкладке (sessionStorage), уходит в X-Impersonate-Token;
 *    - связан с одним админом (actor) — только он им пользуется;
 *    - продлевается на каждом запросе, поэтому «активная» вкладка не
 *      разлогинивается, а брошенная — умирает по TTL.
 *
 * ============================================================
 *  ПОЧЕМУ CACHE, А НЕ ТАБЛИЦА
 * ============================================================
 *
 * - Токены короткоживущие; в БД их держать незачем.
 * - Смена админского пароля/роли должна мгновенно гасить все «смотровые»
 *   сессии → см. forgetAllForActor() (вызывается из сервиса смены пароля).
 * - Нет миграций, нет чистки по cli — Redis/database-cache сами чистят
 *   просроченные ключи.
 *
 * Ключи:
 *   impersonation:handoff:{token} → payload (одноразовый обмен)
 *   impersonation:session:{token} → payload (активная вкладка)
 *   impersonation:actor:{adminId}  → set использованных session-токенов
 *                                    (чтобы уметь отозвать все разом)
 *
 * Payload:
 *   [
 *     'token'      => string,     // сам токен (хранится и в ключе, для удобства)
 *     'actor_id'   => int,        // админ, который инициировал просмотр
 *     'target_id'  => int,        // от чьего лица смотрим
 *     'created_at' => int,        // unix timestamp
 *   ]
 */
final class ImpersonationTokenStore
{
    /** Сколько живёт handoff-токен в URL (сек). Коротко: только на обмен. */
    public const HANDOFF_TTL_MINUTES = 1;

    /** Сколько живёт «смотровая» вкладка без активности (мин). */
    public const SESSION_TTL_MINUTES = 30;

    /** Префиксы ключей кэша (меняется только здесь). */
    private const HANDOFF_PREFIX = 'impersonation:handoff:';
    private const SESSION_PREFIX = 'impersonation:session:';
    private const ACTOR_PREFIX   = 'impersonation:actor:';

    // ------------------------------------------------------------------
    //  HANDOFF TOKEN (одноразовый обмен в новой вкладке)
    // ------------------------------------------------------------------

    /**
     * Выдать одноразовый токен «перехода» в новую вкладку.
     *
     * Вызывается из POST /admin/impersonate/start. Токен не секретный
     * сам по себе — секрет защищён проверкой того, что запрос пришёл от
     * admin-а с активной cookie-сессией (см. ImpersonationController::start).
     */
    public function issueHandoff(int $actorId, int $targetId): string
    {
        $token = $this->generateToken();

        Cache::put(
            self::HANDOFF_PREFIX.$token,
            $this->payload($token, $actorId, $targetId),
            now()->addMinutes(self::HANDOFF_TTL_MINUTES),
        );

        return $token;
    }

    /**
     * Обменять handoff-токен на «сессионный» токен вкладки.
     *
     * Атомарность: на cache-store `redis`/`database` операция pull
     * (get + forget) достаточно защищена от гонок в рамках одного процесса;
     * для одного и того же handoff-токена обмен идёт из одной вкладки, поэтому
     * отдельный Redis-lock не нужен. Если handoff просрочен/уже использован —
     * вернём null.
     *
     * @return array{token:string, actor_id:int, target_id:int, created_at:int}|null
     */
    public function consumeHandoff(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        // Pull: get + forget. Возвращает payload или null.
        $payload = Cache::pull(self::HANDOFF_PREFIX.$token);

        return is_array($payload) ? $payload : null;
    }

    // ------------------------------------------------------------------
    //  SESSION TOKEN (носится вкладкой в заголовке)
    // ------------------------------------------------------------------

    /**
     * Зарегистрировать новую «смотровую» сессию вкладки после обмена
     * handoff → session (см. ImpersonationController::accept).
     */
    public function issueSession(int $actorId, int $targetId): string
    {
        $token = $this->generateToken();

        Cache::put(
            self::SESSION_PREFIX.$token,
            $this->payload($token, $actorId, $targetId),
            now()->addMinutes(self::SESSION_TTL_MINUTES),
        );

        // Индекс «все токены этого админа» — для отзыва всех разом
        // (например, при выходе или смене роли).
        $this->trackForActor($actorId, $token);

        return $token;
    }

    /**
     * Найти активную сессию по токену из заголовка X-Impersonate-Token.
     *
     * НЕ удаляет токен — вкладка может слать его многократно.
     * Побочный эффект: продлевает TTL (скользящее окно активности).
     *
     * @return array{token:string, actor_id:int, target_id:int, created_at:int}|null
     */
    public function findSession(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $key = self::SESSION_PREFIX.$token;
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            return null;
        }

        // Скользящее продление: пока вкладка активна — она живёт.
        // Если вкладку закрыли — ключ умрёт по TTL и просмотр прекратится.
        Cache::put($key, $payload, now()->addMinutes(self::SESSION_TTL_MINUTES));

        return $payload;
    }

    /**
     * Завершить «смотровую» сессию (кнопка «Выйти из просмотра»).
     */
    public function forgetSession(string $token): void
    {
        if ($token === '') {
            return;
        }

        $payload = Cache::get(self::SESSION_PREFIX.$token);
        Cache::forget(self::SESSION_PREFIX.$token);

        if (is_array($payload) && isset($payload['actor_id'])) {
            $this->untrackForActor((int) $payload['actor_id'], $token);
        }
    }

    /**
     * Отозвать ВСЕ «смотровые» сессии конкретного админа.
     *
     * Использовать при: выходе админа, смене пароля, снятии роли admin.
     * Тогда украденный X-Impersonate-Token перестанет работать мгновенно.
     */
    public function forgetAllForActor(int $actorId): void
    {
        $actorKey = self::ACTOR_PREFIX.$actorId;
        /** @var array<int, string> $tokens */
        $tokens = (array) Cache::get($actorKey, []);

        foreach ($tokens as $token) {
            Cache::forget(self::SESSION_PREFIX.$token);
        }

        Cache::forget($actorKey);
    }

    /**
     * Валиден ли токен (без продления) — для status-эндпоинта.
     *
     * @return array{token:string, actor_id:int, target_id:int, created_at:int}|null
     */
    public function peek(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $payload = Cache::get(self::SESSION_PREFIX.$token);

        return is_array($payload) ? $payload : null;
    }

    // ------------------------------------------------------------------
    //  Внутреннее
    // ------------------------------------------------------------------

    /**
     * @return array{token:string, actor_id:int, target_id:int, created_at:int}
     */
    private function payload(string $token, int $actorId, int $targetId): array
    {
        return [
            'token'      => $token,
            'actor_id'   => $actorId,
            'target_id'  => $targetId,
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * Уникальный токен 64 hex-символа (256 бит энтропии).
     */
    private function generateToken(): string
    {
        return Str::random(64);
    }

    private function trackForActor(int $actorId, string $token): void
    {
        $actorKey = self::ACTOR_PREFIX.$actorId;
        /** @var array<int, string> $tokens */
        $tokens = (array) Cache::get($actorKey, []);
        $tokens[] = $token;

        // TTL индекса — максимум сессии, живой токен всё равно не будет
        // удалён молча: индекс только для «удалить всё».
        Cache::put($actorKey, array_values(array_unique($tokens)), now()->addMinutes(self::SESSION_TTL_MINUTES + 5));
    }

    private function untrackForActor(int $actorId, string $token): void
    {
        $actorKey = self::ACTOR_PREFIX.$actorId;
        /** @var array<int, string> $tokens */
        $tokens = (array) Cache::get($actorKey, []);
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== $token));

        if ($tokens === []) {
            Cache::forget($actorKey);

            return;
        }

        Cache::put($actorKey, $tokens, now()->addMinutes(self::SESSION_TTL_MINUTES + 5));
    }
}
