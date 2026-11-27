<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Быстрый поиск по уведомлениям (GET /notifications).
 *
 * 1. `data` переводится из text в jsonb: `->>`-извлечение title становится
 *    нативным, без рантайм-каста text::jsonb на каждой строке.
 * 2. GIN-индекс (pg_trgm) по `lower(data ->> 'title')` обслуживает LIKE '%...%'
 *    с подстрокой — ровно так, как фильтрует EloquentNotificationsQuery.
 * 3. Составной btree (notifiable_type, notifiable_id, created_at DESC) —
 *    фильтр по получателю + сортировка новых-первыми без сортировки в памяти.
 *
 * Всё в духе add_trigram_indexes_for_user_search: выражения в индексе и в
 * запросе совпадают 1:1 (lower + gin_trgm_ops), значение нормализуется в
 * нижний регистр, %/_/\ экранируются в запросе.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');

        DB::statement("CREATE INDEX IF NOT EXISTS notifications_title_trgm_idx
            ON notifications USING gin (lower(data ->> 'title') gin_trgm_ops)");

        DB::statement('CREATE INDEX IF NOT EXISTS notifications_recipient_created_idx
            ON notifications (notifiable_type, notifiable_id, created_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_recipient_created_idx');
        DB::statement('DROP INDEX IF EXISTS notifications_title_trgm_idx');

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        // Расширение pg_trgm не удаляем: оно используется индексами users.
    }
};
