<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowed_devices', function (Blueprint $table) {
            // В Postgres это создаст колонку BIGSERIAL
            $table->id();

            // FingerprintJS возвращает 32-символьную строку
            $table->string('device_id', 32)->unique();

            // Для удобства админа (например, "Кабинет 204, планшет №3")
            $table->string('description')->nullable();

            // В Postgres создаст колонки timestamp без таймзоны
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowed_devices');
    }
};
