<?php
// app/Console/Commands/DbBackupCommand.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Логический бэкап PostgreSQL через pg_dump в custom-формате (-Fc).
 *
 * Результат: <disk>:/<path>/<connection>_<YYYYmmdd_His>.dump
 * Ротация: удаляются дампы старше --days суток.
 *
 * Требует наличия pg_dump в PATH (в Sail-контейнере есть).
 * Пароль передаётся через переменную окружения PGPASSWORD, а не в argv,
 * чтобы не светиться в `ps`.
 */
class DbBackupCommand extends Command
{
    protected $signature = 'db:backup
                            {--connection= : Имя соединения из config/database.php (по умолчанию — default)}
                            {--disk= : Laravel-диск для дампов (по умолчанию BACKUP_DISK)}
                            {--path= : Каталог на диске (по умолчанию BACKUP_PATH)}
                            {--days= : Сколько суток хранить дампы (по умолчанию BACKUP_RETENTION_DAYS)}
                            {--no-prune : Не удалять старые дампы}
                            {--verify : Проверить дамп через pg_restore -l}';

    protected $description = 'Создаёт логический дамп PostgreSQL (pg_dump -Fc) и ротирует старые копии';

    /** Имя Laravel-диска, на который пишутся дампы. */
    protected string $disk = 'local';

    /** Каталог внутри диска (без ведущего и замыкающего слэшей). */
    protected string $path = 'backups';

    public function handle(): int
    {
        $connectionName = (string) ($this->option('connection') ?: config('database.default'));
        $connection = config("database.connections.{$connectionName}");

        if (!is_array($connection)) {
            $this->error("Соединение БД «{$connectionName}» не найдено в config/database.php");
            return self::FAILURE;
        }

        if (($connection['driver'] ?? null) !== 'pgsql') {
            $this->error("Поддерживается только драйвер pgsql, у «{$connectionName}»: " . ($connection['driver'] ?? 'unknown'));
            return self::FAILURE;
        }

        $this->disk = (string) ($this->option('disk') ?: config('database.backup.disk', 'local'));
        $this->path = trim((string) ($this->option('path') ?: config('database.backup.path', 'backups')), '/');
        $days = (int) ($this->option('days') ?: config('database.backup.retention_days', 14));

        if ($days <= 0) {
            $this->error('Параметр --days должен быть больше 0');
            return self::FAILURE;
        }

        $database = (string) $connection['database'];

        if ($database === '') {
            $this->error('Не задано имя базы данных в соединении');
            return self::FAILURE;
        }

        $filename = sprintf('%s_%s.dump', $database, now()->format('Ymd_His'));
        $fullPath = $this->makeDirectory($filename);

        // Проверяем, что живы и клиент pg_dump, и сервер: клиент от разных
        // версий Postgres падает уже после того, как создаст пустой файл.
        if (!$this->preflight($connection, $database)) {
            return self::FAILURE;
        }

        $this->info("Дамп БД «{$database}» → {$this->disk}:{$this->path}/{$filename}");

        $result = Process::timeout((int) config('database.backup.timeout', 600))
            ->env([
                'PGPASSWORD' => (string) ($connection['password'] ?? ''),
                'PGSSLMODE' => (string) ($connection['sslmode'] ?? 'prefer'),
            ])
            ->run([
                'pg_dump',
                '-Fc',
                '--no-owner',
                '--no-privileges',
                '-h', (string) $connection['host'],
                '-p', (string) $connection['port'],
                '-U', (string) $connection['username'],
                '-d', $database,
                '-f', $fullPath,
            ]);

        if (!$result->successful()) {
            $this->error('pg_dump завершился с ошибкой:');
            $this->error(trim($result->errorOutput()) ?: trim($result->output()) ?: '(нет вывода)');

            // Не оставляем битый недописанный файл
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }

            return self::FAILURE;
        }

        $size = is_file($fullPath) ? filesize($fullPath) : 0;

        if ($size < 1024) {
            $this->error("Дамп подозрительно мал ({$size} байт) — файл удалён");
            @unlink($fullPath);
            return self::FAILURE;
        }

        $this->info('Готово: ' . $this->humanSize($size));

        if ($this->option('verify') && !$this->verifyDump($fullPath)) {
            @unlink($fullPath);
            return self::FAILURE;
        }

        if (!$this->option('no-prune')) {
            $this->prune($days);
        }

        return self::SUCCESS;
    }

    /**
     * Убеждается, что pg_dump умеет работать с сервером, до создания файла.
     */
    protected function preflight(array $connection, string $database): bool
    {
        $result = Process::timeout(60)
            ->env([
                'PGPASSWORD' => (string) ($connection['password'] ?? ''),
                'PGSSLMODE' => (string) ($connection['sslmode'] ?? 'prefer'),
            ])
            ->run([
                'pg_dump', '--version',
                '-h', (string) $connection['host'],
                '-p', (string) $connection['port'],
                '-U', (string) $connection['username'],
                '-d', $database,
                '--schema-only', '--schema=', '-f', '/dev/null',
            ]);

        if (!$result->successful()) {
            $this->error('pg_dump не может подключиться к серверу БД:');
            $this->error(trim($result->errorOutput()) ?: trim($result->output()) ?: '(нет вывода)');
            return false;
        }

        return true;
    }

    /**
     * Проверяет, что дамп читается: pg_restore -l разбирает оглавление архива.
     * Ловит обрыв записи и несовместимость форматов до того, как бэкап
     * понадобится.
     */
    protected function verifyDump(string $file): bool
    {
        $result = Process::timeout(120)->run(['pg_restore', '-l', $file]);

        if (!$result->successful()) {
            $this->error('Проверка дампа не прошла:');
            $this->error(trim($result->errorOutput()) ?: trim($result->output()) ?: '(нет вывода)');
            return false;
        }

        $entries = substr_count($result->output(), 'TABLE DATA');

        if ($entries === 0) {
            $this->error('Проверка дампа не прошла: в архиве нет таблиц');
            return false;
        }

        $this->info("Проверка: дамп читается, таблиц в архиве — {$entries}");

        return true;
    }

    /**
     * Создаёт каталог назначения и возвращает абсолютный путь к файлу дампа.
     */
    protected function makeDirectory(string $filename): string
    {
        $base = Storage::disk($this->disk)->path('');

        $dir = rtrim($base, '/') . ($this->path !== '' ? '/' . $this->path : '');

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать каталог бэкапов: {$dir}");
        }

        return $dir . '/' . $filename;
    }

    /**
     * Удаляет дампы *.dump в каталоге, старше N суток.
     */
    protected function prune(int $days): void
    {
        $disk = Storage::disk($this->disk);
        $threshold = now()->subDays($days)->getTimestamp();

        $files = $this->path !== '' ? $disk->files($this->path) : $disk->files('/');

        $deleted = 0;

        foreach ($files as $file) {
            if (!str_ends_with($file, '.dump')) {
                continue;
            }

            try {
                $mtime = $disk->lastModified($file);
            } catch (Throwable) {
                continue;
            }

            if ($mtime >= $threshold) {
                continue;
            }

            $disk->delete($file);
            $this->line("Удалён старый дамп: {$file}");
            $deleted++;
        }

        $this->info("Ротация: удалено {$deleted} файл(ов) старше {$days} сут.");
    }

    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
