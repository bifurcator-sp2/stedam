<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Итоговое решение кворума по агрегату (раздел 1, 3.13).
 *
 * `decision` — строковое представление результата обработки кворума по
 * данным `requested_action_votes`: 'accepted' (approved) / 'declined'
 * (rejected). NULL — кворум ещё не принял решения (created/pending) или
 * агрегат завершился ошибкой.
 *
 * Партиционированная таблица: ADD COLUMN на родителе PG распространяет на
 * все листья. CHECK допускает NULL (`NULL IN (...)` → NULL → PASS).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Колонка может уже существовать из базовой миграции create_requested_actions.
        DB::statement('ALTER TABLE requested_actions ADD COLUMN IF NOT EXISTS decision VARCHAR(16)');

        DB::statement(
            'ALTER TABLE requested_actions DROP CONSTRAINT IF EXISTS requested_actions_decision_check'
        );

        DB::statement(
            "ALTER TABLE requested_actions ADD CONSTRAINT requested_actions_decision_check
                CHECK (decision IN ('accepted','declined'))"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE requested_actions DROP CONSTRAINT IF EXISTS requested_actions_decision_check'
        );

        DB::statement('ALTER TABLE requested_actions DROP COLUMN IF EXISTS decision');
    }
};
