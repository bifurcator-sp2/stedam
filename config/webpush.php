<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Browser push (WebPush / VAPID)
    |--------------------------------------------------------------------------
    |
    | Ключи VAPID можно сгенерировать командой:
    |   php artisan webpush:vapid
    | и положить их в .env (PUSH_VAPID_PUBLIC_KEY / PUSH_VAPID_PRIVATE_KEY).
    |
    | `subject` — mailto: или https-URL, которым push-сервис сможет
    | связаться с нами при проблемах (требуется стандартом VAPID).
    |
    | `ttl` — сколько секунд push-сервис хранит сообщение, пока браузер
    | offline. Дальше сообщение выбрасывается.
    |
    */

    'enabled' => (bool) env('PUSH_ENABLED', true),

    'vapid' => [
        'public_key' => env('PUSH_VAPID_PUBLIC_KEY', ''),
        'private_key' => env('PUSH_VAPID_PRIVATE_KEY', ''),
        'subject' => env('PUSH_VAPID_SUBJECT', 'mailto:admin@example.com'),
    ],

    'ttl' => (int) env('PUSH_TTL', 86400),

    /*
    | Ургентность доставки: 'very-low' | 'low' | 'normal' | 'high'.
    | 'normal' — разбудит устройство; экономит батарею лучше 'high'.
    */
    'urgency' => env('PUSH_URGENCY', 'normal'),

    /*
    | Заголовок/текст/адрес клика по умолчанию. Если уведомление реализует
    | метод toWebPush(), его результат имеет приоритет.
    */
    'defaults' => [
        'title' => env('PUSH_DEFAULT_TITLE', 'Новое уведомление'),
        'url' => env('PUSH_DEFAULT_URL', '/app/notifications'),
        'icon' => env('PUSH_DEFAULT_ICON', '/favicon.ico'),
        'badge' => env('PUSH_DEFAULT_BADGE', '/favicon.ico'),
    ],
];
