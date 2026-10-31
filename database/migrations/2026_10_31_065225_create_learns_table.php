<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learns', function (Blueprint $table) {
            $table->id();

            // Связи
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->index(); // Явный индекс (хотя constrained() его создает, для наглядности можно оставить)

            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete();

            // Данные
            $table->jsonb('lesson')->nullable(); // храним снапшот или состояние
            $table->timestampTz('complete')->nullable()->index(); // время завершения
            $table->float('score')->default(0)->index(); // оценка/баллы

            $table->timestampsTz();

            // Составной индекс для быстрого поиска прогресса конкретного юзера по уроку
            $table->index(['user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learns');
    }
};
