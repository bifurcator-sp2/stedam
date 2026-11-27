<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подписки браузерных push-уведомлений (WebPush / VAPID).
 *
 * Одна строка = одна подписка PushManager одного браузера. У пользователя
 * их может быть сколько угодно (несколько устройств/браузеров).
 *
 * `endpoint` — уникален глобально (принадлежит конкретному push-сервису),
 * повторная подписка того же устройства обновляет её через upsert.
 * `p256dh`/`auth` — клиентские ключи шифрования; в `content_encoding`
 * хранится кодировка ключей ('aesgcm' | 'aes128gcm') из подписки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint')->unique();
            $table->string('public_key');  // p256dh
            $table->string('auth_token');  // auth
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
