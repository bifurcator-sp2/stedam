# Браузерные push‑уведомления (Web Push / VAPID)

Документ описывает, как в проекте stedam устроен канал браузерных push‑уведомлений:
что уже реализовано на бэкенде, что должен сделать фронтенд (SPA в `public/app`),
и что нужно настроить в окружении, чтобы push заработал end‑to‑end.

Текст push‑а всегда фиксированный — **`NewNotifications`** (title, body и тип).
Push отправляется при **каждом** уведомлении, доставленном пользователю в канал
`database`, независимо от класса `Notification`.

---

## 1. Общая схема

```
Notification::send()
      │
      ▼
 Illuminate NotificationSender
      │  диспатчит канал 'database' (запись в таблицу notifications)
      ▼
 Event NotificationSent(channel = 'database')
      │
      ▼
 App\Listeners\Notifications\SendBrowserPushForDatabaseNotifications
      │  берёт все push_subscriptions пользователя
      ▼
 App\Infrastructure\WebPush\WebPushSender
      │  шифрует payload (aes128gcm), подписывает VAPID
      ▼
 GuzzleWebPushTransport ──HTTP POST──▶ push-сервис браузера
                                             │
                                             ▼
                              Service Worker (клиент) → showNotification('NewNotifications')
```

Ключевые свойства:

- слушатель реагирует **только** на `channel === 'database'` и только если
  получатель — `App\Models\User`; мультиканальная нотификация даст ровно один push;
- push идёт «после факта»: запись в `notifications` уже создана, поэтому клик по
  уведомлению ведёт на реально существующую запись;
- трафик push‑сервиса не блокирует HTTP‑ответ, если сама `Notification`
  реализует `ShouldQueue` (наш `RequestedActionNotification` — реализует);
  иначе отправка синхронная внутри слушателя.

---

## 2. Бэкенд: что уже сделано

### 2.1 Хранилище подписок

Миграция `database/migrations/2026_12_10_100000_create_push_subscriptions_table.php`:

| колонка | тип | назначение |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK → `users.id`, cascade delete | владелец подписки |
| `endpoint` | text, **unique** | URL push‑сервиса, одно устройство/браузер = одна строка |
| `public_key` | string | `keys.p256dh` из `PushSubscription.toJSON()` |
| `auth_token` | string | `keys.auth` |
| `content_encoding` | string(16), default `aes128gcm` | |
| `user_agent` | string, nullable | аналитика |
| `created_at` / `updated_at` | timestamps | |

Индекс по `user_id` — выборка всех подписок пользователя при рассылке.

Модель `App\Models\PushSubscription` скрывает `endpoint`, `public_key`,
`auth_token` от сериализации. Связь — `User::pushSubscriptions()` (HasMany).

### 2.2 API подписок

| Метод | Путь | Авторизация | Назначение |
|---|---|---|---|
| `GET` | `/api/push/vapid-public-key` | нет | `{ "publicKey": "<base64url>" }` |
| `POST` | `/api/push/subscriptions` | `auth:sanctum`, `throttle:30,1` | создать/обновить подписку → `201 {"message":"Subscribed"}` |
| `DELETE` | `/api/push/subscriptions` | `auth:sanctum` | удалить по `endpoint` → `200 {"message":"Unsubscribed"}` |

`POST /api/push/subscriptions` — тело:

```json
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/...",
  "keys": { "p256dh": "<base64url>", "auth": "<base64url>" },
  "content_encoding": "aes128gcm"
}
```

- `endpoint` — обязателен, `url`, ≤ 2048;
- `keys.p256dh`, `keys.auth` — обязательны, ≤ 255;
- `content_encoding` — опционально, `aes128gcm` (по умолчанию) или `aesgcm`;
- подписка идентифицируется по `endpoint`: повторный POST обновляет ключи и
  перепривязывает подписку к текущему пользователю (сменился аккаунт — подписка
  уходит новому владельцу).

`DELETE` идемпотентен: отсутствующая подписка — тоже `200`.

Реализация: `App\Http\Controllers\Api\PushSubscriptionsController` +
экшены в `App\Http\Controllers\Api\PushSubscriptionsController/`
(`VapidPublicKeyAction`, `StorePushSubscriptionAction`, `DestroyPushSubscriptionAction`).
Маршруты — в `routes/api.php`, секция «Браузерные push».

### 2.3 Отправка

- `App\Infrastructure\WebPush\WebPushTransportInterface::send(array $subscription, array $payload): WebPushResult`
- `App\Infrastructure\WebPush\Aes128GcmWebPushEncoder` — шифрование payload
  по RFC 8188 (`aes128gcm`), ECDH P‑256 + HKDF‑SHA256 + AES‑128‑GCM, VAPID JWT
  ES256 по RFC 8292. Только `ext-openssl`, без сторонних Composer‑пакетов.
- `App\Infrastructure\WebPush\GuzzleWebPushTransport` — HTTP POST на `endpoint`
  с заголовками `Content-Encoding: aes128gcm`, `TTL`, `Urgency`,
  `Authorization: vapid t=<jwt>, k=<publicKey>`.
- `App\Infrastructure\WebPush\WebPushSender` — рассылка по всем подпискам
  пользователя. Политика сбоев:
  - `404`/`410` → подписка удаляется из БД;
  - прочие ошибки → `Log::warning`, подписка остаётся;
  - исключения наружу не пробрасываются (push не должен ломать доставку).
- `App\Providers\WebPushServiceProvider` — биндинг транспорта и регистрация
  слушателя `NotificationSent`.

### 2.4 Конфиг `config/webpush.php`

| ключ | env | default | |
|---|---|---|---|
| `enabled` | `PUSH_ENABLED` | `true` | глобальный выключатель |
| `vapid.public_key` | `PUSH_VAPID_PUBLIC_KEY` | — | base64url |
| `vapid.private_key` | `PUSH_VAPID_PRIVATE_KEY` | — | base64url |
| `vapid.subject` | `PUSH_VAPID_SUBJECT` | `mailto:admin@example.com` | `mailto:` или URL |
| `ttl` | `PUSH_TTL` | `86400` | секунды «жизни» push в сервисе |
| `urgency` | `PUSH_URGENCY` | `normal` | `very-low`\|`low`\|`normal`\|`high` |
| `defaults.url` | — | `/app/notifications` | куда вести клик |
| `defaults.title` / `icon` / `badge` | — | — | запасные значения для клиента |

### 2.5 Payload

```json
{
  "type": "NewNotifications",
  "title": "NewNotifications",
  "body": "NewNotifications",
  "url": "/app/notifications",
  "data": { "...": "содержимое toDatabase()/toArray() уведомления" }
}
```

`url` формируется так: если в данных уведомления есть строковый `approve_url`
(requested actions), то `/app/<approve_url>`; иначе `config('webpush.defaults.url')`.

### 2.6 Тесты

- `tests/Unit/Infrastructure/WebPush/WebPushTransportTest.php` — расшифровка
  aes128gcm на стороне подписчика, проверка подписи VAPID JWT, классификация
  ответов push‑сервиса (`201`/`404`/`410`/`500`).
- `tests/Feature/Notifications/WebPushNotificationTest.php` — push при
  database‑канале, отсутствие push на других каналах, авторизация и валидация
  эндпоинтов, публичный VAPID‑ключ.
- `tests/Feature/Notifications/WebPushSenderTest.php` — payload `NewNotifications`,
  игнор других каналов, удаление подписки при `410`.

Запуск (внутри Sail):

```bash
docker exec stedam-laravel.test-1 php artisan test tests/Feature/Notifications tests/Unit/Infrastructure/WebPush
```

---

## 3. Что нужно сделать, чтобы заработало

### 3.1 Сгенерировать VAPID‑ключи

```bash
php artisan webpush:vapid --subject="mailto:admin@stedam.localhost"
```

Команда печатает три строки — добавить их в `.env`:

```dotenv
PUSH_ENABLED=true
PUSH_VAPID_PUBLIC_KEY=<base64url>
PUSH_VAPID_PRIVATE_KEY=<base64url>
PUSH_VAPID_SUBJECT=mailto:admin@stedam.localhost
```

Затем:

```bash
php artisan config:clear
```

**Ключи обязаны совпадать с публичным ключом, который отдаёт
`GET /api/push/vapid-public-key`**, и не должны меняться после того, как
пользователи подписались: при смене ключей все существующие подписки станут
невалидными (push‑сервис ответит `403`), их надо переоформить на клиенте.
Приватный ключ — секрет, в git не коммитить.

### 3.2 HTTPS / origin

Service Worker и Push API работают только в **secure context**: `https://`
или `http://localhost`. Для локальной разработки `http://stedam.localhost`
в Chromium/Firefox считается безопасным origin (`.localhost` — secure), но
другие хосты вроде `http://stedam.192.168.0.10.nip.io` без TLS — нет.
В продакшене — обязателен HTTPS.

Origin, на котором работает SPA, должен совпадать с origin, где регистрируется
Service Worker и делаются запросы к API (иначе CORS/credentials настраивать
отдельно — сейчас SPA и API живут на одном хосте).

### 3.3 Каналы: уведомление должно попадать в `database`

Слушатель смотрит на событие `NotificationSent` с `channel === 'database'`.
Значит, у класса `Notification` в `via()` должен быть `'database'`
(у `RequestedActionNotification` он есть, `getNotificationChannels()` в хэндлерах
возвращает `['database']`). Если уведомление отправляется только по `mail` —
push не будет.

---

## 4. Фронтенд (SPA) — что реализовать

Бэкенд отдаёт контракт, но push заработает только после реализации на клиенте.
SPA лежит в `public/app` (Nuxt, собранный в `public/app/_nuxt`).

### 4.1 Service Worker `public/sw.js`

Обязан лежать в **корне origin** (`/sw.js`), чтобы иметь scope `/`.

```js
// public/sw.js
self.addEventListener('push', (event) => {
  let payload = {};
  try { payload = event.data ? event.data.json() : {}; } catch (_) {}

  const title = payload.title || 'NewNotifications';
  event.waitUntil(
    self.registration.showNotification(title, {
      body: payload.body || 'NewNotifications',
      icon: '/favicon.ico',
      badge: payload.badge || undefined,
      data: { url: payload.url || '/app/notifications', raw: payload },
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/app/notifications';

  event.waitUntil((async () => {
    const clientsList = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of clientsList) {
      if ('focus' in c) { c.navigate?.(url); return c.focus(); }
    }
    return clients.openWindow(url);
  })());
});

// Браузер может сам инвалидировать подписку — переоформляем молча,
// если пользователь уже дал разрешение.
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const res = await fetch('/api/push/vapid-public-key', { credentials: 'include' });
    const { publicKey } = await res.json();
    const sub = await self.registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(publicKey),
    });
    await fetch('/api/push/subscriptions', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(sub.toJSON()),
    });
  })());
});

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  const out = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; ++i) out[i] = raw.charCodeAt(i);
  return out;
}
```

> Vite/Nuxt собирает `_nuxt`, но `sw.js` должен быть статическим файлом в
> `public/` — не хешировать, не прогонять через бандлер. Если нужен другой путь
> (например, `/app/sw.js`), передайте `{ scope: '/app/' }` в `register`
> и учитывайте, что push будет показываться только для страниц под этим scope.

### 4.2 Регистрация SW и подписка

Показывать запрос разрешения только по явному действию пользователя (кнопка
«Включить уведомления» в профиле) — браузеры игнорируют запрос без user gesture.

```ts
// composables/useBrowserPush.ts
export async function enablePush(): Promise<'granted' | 'denied' | 'unsupported'> {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return 'unsupported';

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') return 'denied';

  const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
  await navigator.serviceWorker.ready;

  const { publicKey } = await $fetch<{ publicKey: string }>('/api/push/vapid-public-key');

  const sub = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(publicKey),
  });

  await $fetch('/api/push/subscriptions', {
    method: 'POST',
    body: sub.toJSON(),
  });

  return 'granted';
}

export async function disablePush(): Promise<void> {
  const reg = await navigator.serviceWorker.getRegistration('/');
  const sub = await reg?.pushManager.getSubscription();
  if (!sub) return;
  const endpoint = sub.endpoint;
  await sub.unsubscribe();
  await $fetch('/api/push/subscriptions', { method: 'DELETE', body: { endpoint } });
}
```

`sub.toJSON()` уже возвращает `{ endpoint, expirationTime, keys: { p256dh, auth } }`
в требуемом формате — отправлять как есть.

### 4.3 Триггеры клиента

- **вход в систему**: если `Notification.permission === 'granted'` и подписки
  нет (`getSubscription() === null`), вызвать подписку автоматически;
- **выход из системы**: `disablePush()` (или явная отписка в настройках) —
  иначе push‑и придут на чужое устройство; при смене пользователя
  повторный POST перепривяжет `endpoint` к новому `user_id` (см. §2.2);
- **кнопка в профиле**: переключатель `enabled/disabled`;
- **страница уведомлений**: по клику на push открывается `payload.url`
  (обычно `/app/notifications` или `/app/approve-action/form?...`).

### 4.4 Тонкости

- iOS Safari: push работают только в PWA, установленном на домашний экран
  (iOS 16.4+), в обычной вкладке — `PushManager` отсутствует;
- если браузер вернул `403`/`410` — подписка протухла; бэкенд уже удалит её на
  `410`, клиенту достаточно вызвать `pushManager.unsubscribe()` и подписаться снова;
- проверка поддержки: `'PushManager' in window` и `'serviceWorker' in navigator`.

---

## 5. Проверка end‑to‑end

1. `php artisan webpush:vapid` → ключи в `.env` → `php artisan config:clear`.
2. Открыть `http://stedam.localhost/app`, войти, включить уведомления в профиле.
3. DevTools → Application → Service Workers: `/sw.js` активен, scope `/`.
4. В БД появится запись в `push_subscriptions`.
5. Триггернуть database‑уведомление, например через RequestedAction
   (создать запрос и дождаться перехода в `approved`/`rejected`) или вручную:

   ```bash
   docker exec stedam-laravel.test-1 php artisan tinker --execute="\
     \App\Models\User::first()->notify(new class extends \Illuminate\Notifications\Notification { \
       public function via(\$n): array { return ['database']; } \
       public function toArray(\$n): array { return ['title' => 'Test']; } \
     });"
   ```

6. Ожидаемо: в `storage/logs/laravel.log` нет `WebPush send failed`,
   в браузере показывается notification с текстом `NewNotifications`,
   клик открывает указанный `url`.
7. Негативный кейс: удалить подписку в настройках браузера и отправить push —
   бэкенд получит `410` и удалит строку из `push_subscriptions`.

---

## 6. Диагностика

| Симптом | Причина / решение |
|---|---|
| `WebPush send failed ... status: 401/403` | `PUSH_VAPID_*` не совпадают или ключи сменены после подписки — перегенерировать и переподписать клиентов |
| push‑ы не приходят, в логе `WebPush send failed ... 410` | подписка протухла, клиент должен подписаться заново |
| `endpoint` сохранён, но push нет | проверь `PUSH_ENABLED` и что уведомление реально в канале `database` |
| `ServiceWorker registration failed` | `sw.js` не в корне origin или отдан не с `Content-Type: application/javascript` |
| `applicationServerKey` невалиден | `publicKey` из API не base64url‑декодирован перед subscribe |
| `PERMISSION_DENIED` | запрос разрешения без user gesture |

---

## 7. Возможные улучшения (не обязательны)

- группировка push по `tag` в `showNotification` (`tag: 'notifications'`) —
  чтобы несколько уведомлений не плодили стопку;
- подпись `data.notifications_count`/дайджест вместо фиксированного текста;
- вынести VAPID ключи в secrets‑хранилище прод‑окружения.
