<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Триграм-индексы для поиска вхождений (LIKE '%...%') в GET /users.
 *
 * Расширение pg_trgm даёт классы операторов gin_trgm_ops: GIN-индекс с ним
 * обслуживает LIKE/ILIKE с подстрокой. Без него `LIKE '%...%'` в PostgreSQL
 * уходит в seq scan (лидирующий wildcard отключает btree).
 *
 * Индексируем ровно те колонки, по которым фильтрует EloquentUsersQuery:
 *   — users.email;
 *   — user_data.first_name / last_name / middle_name.
 *
 * Индексы строятся по lower(col): поиск в EloquentUsersQuery выполняется
 * как lower(col) LIKE '...', чтобы GIN-индекс (выражение lower) применился.
 * Значение в запросе нормализуется в нижний регистр, %/_/\\ экранируются.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CREATE EXTENSION требует прав суперпользователя/владельца БД;
        // в контейнере Sail это ок. IF NOT EXISTS — миграция идемпотентна.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS users_email_trgm_idx
            ON users USING gin (lower(email) gin_trgm_ops)');

        DB::statement('CREATE INDEX IF NOT EXISTS user_data_first_name_trgm_idx
            ON user_data USING gin (lower(first_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS user_data_last_name_trgm_idx
            ON user_data USING gin (lower(last_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS user_data_middle_name_trgm_idx
            ON user_data USING gin (lower(middle_name) gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS user_data_middle_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS user_data_last_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS user_data_first_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS users_email_trgm_idx');
        // Расширение не удаляем: оно может использоваться другими объектами.
    }
};
