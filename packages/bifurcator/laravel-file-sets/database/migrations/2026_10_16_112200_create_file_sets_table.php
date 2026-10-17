<?php
// packages/bifurcator/laravel-file-sets/database/migrations/2026_10_16_112200_create_file_sets_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. Таблица
        // ============================================================

        if (!Schema::hasTable('file_sets')) {
            Schema::create('file_sets', function (Blueprint $table) {
                $table->id();
                $table->string('model');
                $table->unsignedBigInteger('model_id');
                $table->string('purpose');
                $table->json('images')->nullable();
                $table->json('files')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['model', 'model_id', 'purpose']);
            });

            return;
        }

        // ============================================================
        // 1.1. Снести все индексы, кроме primary (id)
        // ============================================================

        $this->dropAllIndexesExceptPrimary('file_sets');

        // ============================================================
        // 2. Поля — добавить отсутствующие И привести существующие к нужному виду
        // ============================================================

        // --- model ---
        if (!Schema::hasColumn('file_sets', 'model')) {
            Schema::table('file_sets', function (Blueprint $table) {
                $table->string('model')->after('id');
            });
        } elseif (!$this->columnIsString('file_sets', 'model')) {
            $this->changeColumn('file_sets', 'model', function (Blueprint $table) {
                $table->string('model')->change();
            });
        }

        // --- model_id ---
        if (!Schema::hasColumn('file_sets', 'model_id')) {
            Schema::table('file_sets', function (Blueprint $table) {
                $table->unsignedBigInteger('model_id')->after('model');
            });
        } elseif (!$this->columnIsUnsignedBigInteger('file_sets', 'model_id')) {
            $this->changeColumn('file_sets', 'model_id', function (Blueprint $table) {
                $table->unsignedBigInteger('model_id')->change();
            });
        }

        // --- purpose ---
        if (!Schema::hasColumn('file_sets', 'purpose')) {
            Schema::table('file_sets', function (Blueprint $table) {
                $table->string('purpose')->after('model_id');
            });
        } elseif (!$this->columnIsString('file_sets', 'purpose')) {
            $this->changeColumn('file_sets', 'purpose', function (Blueprint $table) {
                $table->string('purpose')->change();
            });
        }

        // --- images ---
        if (!Schema::hasColumn('file_sets', 'images')) {
            Schema::table('file_sets', function (Blueprint $table) {
                $table->json('images')->nullable()->after('purpose');
            });
        } elseif (!$this->columnIsJson('file_sets', 'images')) {
            $this->changeColumn('file_sets', 'images', function (Blueprint $table) {
                $table->json('images')->nullable()->change();
            });
        }

        // --- files ---
        if (!Schema::hasColumn('file_sets', 'files')) {
            Schema::table('file_sets', function (Blueprint $table) {
                $table->json('files')->nullable()->after('images');
            });
        } elseif (!$this->columnIsJson('file_sets', 'files')) {
            $this->changeColumn('file_sets', 'files', function (Blueprint $table) {
                $table->json('files')->nullable()->change();
            });
        }

        // --- created_at ---
        if (!Schema::hasColumn('file_sets', 'created_at')) {
            Schema::table('file_sets', function (Blueprint $table) {
                $table->timestamp('created_at')->nullable();
            });
        } elseif (!$this->columnIsTimestamp('file_sets', 'created_at')) {
            $this->changeColumn('file_sets', 'created_at', function (Blueprint $table) {
                $table->timestamp('created_at')->nullable()->change();
            });
        }

        // --- updated_at ---
        if (!Schema::hasColumn('file_sets', 'updated_at')) {
            Schema::table('file_sets', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable();
            });
        } elseif (!$this->columnIsTimestamp('file_sets', 'updated_at')) {
            $this->changeColumn('file_sets', 'updated_at', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable()->change();
            });
        }

        // --- deleted_at ---
        if (!Schema::hasColumn('file_sets', 'deleted_at')) {
            Schema::table('file_sets', function (Blueprint $table) {
                $table->softDeletes();
            });
        } elseif (!$this->columnIsTimestamp('file_sets', 'deleted_at')) {
            $this->changeColumn('file_sets', 'deleted_at', function (Blueprint $table) {
                $table->timestamp('deleted_at')->nullable()->change();
            });
        }

        // ============================================================
        // 3. Индекс
        // ============================================================

        if (!$this->hasIndex('file_sets', 'file_sets_model_model_id_purpose_index')) {
            Schema::table('file_sets', function (Blueprint $table) {
                $table->index(['model', 'model_id', 'purpose']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_sets');
    }

    // ============================================================
    // Хелперы
    // ============================================================

    /**
     * Удаляет все индексы таблицы, кроме primary.
     */
    protected function dropAllIndexesExceptPrimary(string $table): void
    {
        try {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();

            // На всякий случай гарантируем, что префикс/схема учтены
            $indexes = $sm->listTableIndexes($table);
        } catch (\Throwable $e) {
            Log::warning("Не удалось получить список индексов {$table}: " . $e->getMessage());
            return;
        }

        foreach ($indexes as $name => $index) {
            if ($index->isPrimary()) {
                continue;
            }

            try {
                Schema::table($table, function (Blueprint $blueprint) use ($name) {
                    $blueprint->dropIndex($name);
                });
            } catch (\Throwable $e) {
                Log::warning("Не удалось удалить индекс {$table}.{$name}: " . $e->getMessage());
            }
        }
    }

    /**
     * Безопасно применяет изменение колонки.
     */
    protected function changeColumn(string $table, string $column, \Closure $callback): void
    {
        try {
            Schema::table($table, $callback);
        } catch (\Throwable $e) {
            Log::warning(
                "Не удалось привести колонку {$table}.{$column} к нужному виду: " . $e->getMessage()
            );
        }
    }

    protected function columnDoctrineType(string $table, string $column): ?string
    {
        try {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $columns = $sm->listTableColumns($table);

            if (!isset($columns[$column])) {
                return null;
            }

            return $columns[$column]->getType()->getName();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function columnIsString(string $table, string $column): bool
    {
        $type = $this->columnDoctrineType($table, $column);
        return in_array($type, ['string', 'text'], true);
    }

    protected function columnIsJson(string $table, string $column): bool
    {
        $type = $this->columnDoctrineType($table, $column);
        return in_array($type, ['json', 'json_array'], true);
    }

    protected function columnIsTimestamp(string $table, string $column): bool
    {
        $type = $this->columnDoctrineType($table, $column);
        return in_array($type, ['datetime', 'datetimetz', 'timestamp'], true);
    }

    protected function columnIsUnsignedBigInteger(string $table, string $column): bool
    {
        try {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $columns = $sm->listTableColumns($table);

            if (!isset($columns[$column])) {
                return false;
            }

            $col = $columns[$column];
            $type = $col->getType()->getName();

            if ($type !== 'bigint') {
                return false;
            }

            $options = $col->getPlatformOptions();
            return !empty($options['unsigned']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function hasIndex(string $table, string $indexName): bool
    {
        try {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes($table);

            return array_key_exists($indexName, $indexes);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
