<?php
// packages/bifurcator/laravel-file-sets/database/migrations/2026_10_16_112200_create_file_sets_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Политика для PostgreSQL:
     *
     *  1. Если таблица уже создана приложением (baseline-миграцией) —
     *     ничего не делаем. Baseline владеет схемой: jsonb, timestamptz,
     *     UNIQUE(model, model_id, purpose).
     *
     *  2. Если таблицы нет (пакет ставится в чистый проект без baseline) —
     *     создаём сразу в PG-нативном виде: jsonb + timestamptz + UNIQUE.
     *
     *  3. Реконсиляционная логика (changeColumn, dropAllIndexesExceptPrimary,
     *     Doctrine-хелперы) удалена полностью:
     *      - на Laravel 11+ getDoctrineSchemaManager() не существует;
     *      - на PG она разрушает jsonb → json и timestamptz → timestamp;
     *      - она сносит UNIQUE-индекс и заменяет его обычным.
     */
    public function up(): void
    {
        if (Schema::hasTable('file_sets')) {
            return;
        }

        Schema::create('file_sets', function (Blueprint $table) {
            $table->id();

            // Полиморфная привязка к владельцу (Block, LessonBlock, ...)
            $table->string('model');
            $table->unsignedBigInteger('model_id');

            // Назначение (FilePurpose enum как строка)
            $table->string('purpose')->default('cover');

            // PG-native: jsonb быстрее json, индексируется GIN
            $table->jsonb('images')->nullable();
            $table->jsonb('files')->nullable();

            // PG-native: timestamptz корректно работает с TZ
            $table->timestampsTz();
            $table->softDeletesTz();

            // Индексы
            $table->index(['model', 'model_id'], 'file_sets_model_model_id_index');
            $table->unique(
                ['model', 'model_id', 'purpose'],
                'file_sets_model_model_id_purpose_unique',
            );
        });
    }

    public function down(): void
    {
        // Таблица принадлежит baseline приложения.
        // Пакет не должен её дропать, иначе откат миграции
        // уничтожит данные приложения.
    }
};
