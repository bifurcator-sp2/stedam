<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица агрегата RequestedAction с двухуровневым партиционированием (2.8).
 *
 * Дерево:
 *   requested_actions                      -- PARTITION BY LIST (complete)
 *   ├── requested_actions_active            -- complete = false, PARTITION BY RANGE (created_at)
 *   │   ├── requested_actions_active_<Y_m>
 *   └── requested_actions_complete          -- complete = true,  PARTITION BY RANGE (created_at)
 *       ├── requested_actions_complete_<Y_m>
 *
 * Обязательные свойства схемы:
 *  - PK (id, complete, created_at) — PG требует все ключи секционирования;
 *  - во внешней транзакции должен быть INSERT «по одной таблице»: строка
 *    физически лежит в помесячном листе;
 *  - `payload_hash` — generated column md5(payload::text); UNIQUE
 *    (user_id, handler, payload_hash) ставится на КАЖДЫЙ активный лист
 *    (2.8.1) — ON CONFLICT DO NOTHING без колонок работает только так;
 *  - generated column нельзя использовать как ключ партиционирования.
 *
 * Помесячные листья создаёт EnsureRequestedActionPartitionAction перед INSERT.
 * Миграция создаёт только лист текущего месяца в активной ветке: пустая
 * завершённая ветка при первом старте вакуумна для обоих воркеров retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE requested_actions (
                id           BIGSERIAL,
                user_id      BIGINT      NOT NULL,
                handler      VARCHAR(64) NOT NULL,
                payload      JSONB       NOT NULL,
                payload_hash VARCHAR(32) GENERATED ALWAYS AS (md5(payload::text)) STORED,
                status       VARCHAR(16) NOT NULL DEFAULT 'created',
                decision     VARCHAR(16),
                complete     BOOLEAN     NOT NULL DEFAULT false,
                error_code   VARCHAR(64),
                error_detail TEXT,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT requested_actions_pkey PRIMARY KEY (id, complete, created_at),
                CONSTRAINT requested_actions_status_check
                    CHECK (status IN ('created','pending','approved','rejected','done','error')),
                CONSTRAINT requested_actions_decision_check
                    CHECK (decision IN ('accepted','declined')),
                CONSTRAINT requested_actions_complete_check CHECK (complete IN (false, true))
            ) PARTITION BY LIST (complete)
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE requested_actions_active PARTITION OF requested_actions
                FOR VALUES IN (false) PARTITION BY RANGE (created_at)
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE requested_actions_complete PARTITION OF requested_actions
                FOR VALUES IN (true) PARTITION BY RANGE (created_at)
        SQL);

        // Лист текущего месяца активной ветки: первый INSERT не должен
        // зависеть от прогона EnsureRequestedActionPartitionAction.
        $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('first day of this month')
            ->setTime(0, 0, 0);

        $this->createLeaf(
            'active',
            $start->format('Y_m'),
            $start->format('Y-m-d H:i:sP'),
            $start->modify('+1 month')->format('Y-m-d H:i:sP'),
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS requested_actions CASCADE');
    }

    private function createLeaf(string $branch, string $suffix, string $from, string $to): void
    {
        $table = sprintf('requested_actions_%s_%s', $branch, $suffix);

        DB::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s PARTITION OF requested_actions_%s FOR VALUES FROM (%s) TO (%s)',
            $table,
            $branch,
            DB::getPdo()->quote($from),
            DB::getPdo()->quote($to),
        ));

        // Индексы листа. Для завершённой ветки UNIQUE не нужен
        // (идемпотентность касается только активных).
        if ($branch === 'active') {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (user_id, handler, payload_hash)',
                "{$table}_identity_unique",
                $table,
            ));
        }

        DB::statement(sprintf(
            "CREATE INDEX IF NOT EXISTS %s ON %s (status, updated_at) WHERE status = 'approved'",
            "{$table}_approved_idx",
            $table,
        ));
    }
};
