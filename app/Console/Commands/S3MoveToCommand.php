<?php
// app/Console/Commands/S3MoveToCommand.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Переносит файлы из локального диска в S3, сохраняя структуру каталогов.
 *
 * Основной сценарий — переезд с локального `public` на `s3_public`:
 *
 *   php artisan s3:moveTo
 *   php artisan s3:moveTo models/file_sets --dry-run
 *   php artisan s3:moveTo models/file_sets --keep --delete-empty-dirs
 *   php artisan s3:moveTo models/file_sets --to=s3_private --from=public
 *
 * После успешного переноса (без --keep) исходные файлы удаляются,
 * а исходные пустые каталоги — опционально (--delete-empty-dirs).
 *
 * Путь в БД не меняется: он относительный, поэтому смена диска
 * делается только в .env (FILE_SETS_STORE_DISK, FILE_SETS_URL_RESOLVER).
 */
class S3MoveToCommand extends Command
{
    protected $signature = 's3:moveTo
        {path=models/file_sets : Путь относительно исходного диска (по умолчанию storage/app/public/models/file_sets)}
        {--from=public           : Исходный диск (см. config/filesystems.php)}
        {--to=s3_public          : Целевой диск}
        {--keep                  : Не удалять исходные файлы после успешного переноса}
        {--force                 : Перезаписывать в S3, даже если размер совпадает}
        {--dry-run               : Только показать, что будет сделано}
        {--delete-empty-dirs     : Удалить пустые каталоги на исходном диске после переноса}';

    protected $description = 'Переносит файлы с локального диска в S3-бакет, сохраняя структуру путей';

    public function handle(): int
    {
        $path      = trim((string) $this->argument('path'), '/');
        $fromDisk  = (string) $this->option('from');
        $toDisk    = (string) $this->option('to');
        $keep      = (bool)   $this->option('keep');
        $force     = (bool)   $this->option('force');
        $dryRun    = (bool)   $this->option('dry-run');

        if (!config("filesystems.disks.{$fromDisk}")) {
            $this->error("Диск {$fromDisk} не сконфигурирован");
            return self::FAILURE;
        }
        if (!config("filesystems.disks.{$toDisk}")) {
            $this->error("Диск {$toDisk} не сконфигурирован");
            return self::FAILURE;
        }

        /** @var Filesystem $source */
        $source = Storage::disk($fromDisk);
        /** @var Filesystem $target */
        $target = Storage::disk($toDisk);

        if (!$source->exists($path)) {
            $this->error("Каталог «{$path}» не найден на диске {$fromDisk}");
            return self::FAILURE;
        }

        $this->info("Перенос  {$fromDisk}:{$path}  →  {$toDisk}:{$path}");
        if ($dryRun) {
            $this->warn('DRY-RUN: файлы и каталоги не будут изменены');
        }

        $files = $source->allFiles($path);

        if (empty($files)) {
            $this->warn('Файлов для переноса не найдено.');
            return self::SUCCESS;
        }

        $this->line('Файлов: ' . count($files));

        $bar = $this->output->createProgressBar(count($files));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');
        $bar->start();

        $moved = $skipped = $failed = 0;

        foreach ($files as $file) {
            $bar->advance();

            try {
                $result = $this->transferOne($source, $target, $file, $force, $dryRun);

                if ($result === 'skipped') {
                    $skipped++;
                } else {
                    $moved++;
                }

                // Удаляем источник, если это успешный перенос или файл уже на месте
                if (!$keep && !$dryRun && in_array($result, ['moved', 'skipped'], true)) {
                    $source->delete($file);
                }
            } catch (\Throwable $e) {
                $failed++;
                $bar->clear();
                $this->newLine();
                $this->error("[{$file}] " . $e->getMessage());
                $bar->display();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Moved / already there', 'Skipped', 'Failed', 'Source kept'],
            [[$moved, $skipped, $failed, $keep ? 'yes' : 'no']],
        );

        if ($this->option('delete-empty-dirs') && !$dryRun) {
            $this->deleteEmptyDirs($source, $path);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return string 'moved' | 'skipped'
     */
    protected function transferOne(
        Filesystem $source,
        Filesystem $target,
        string $file,
        bool $force,
        bool $dryRun,
    ): string {
        $srcSize = $source->size($file);

        // Идемпотентность: если файл уже в S3 с тем же размером — пропускаем
        if (!$dryRun && !$force && $target->exists($file)) {
            $tgtSize = $target->size($file);
            if ($tgtSize === $srcSize) {
                return 'skipped';
            }
            // размеры разные — перезапишем
        }

        if ($dryRun) {
            return 'moved';
        }

        $mime = $source->mimeType($file) ?: 'application/octet-stream';

        $stream = $source->readStream($file);
        if ($stream === null) {
            throw new \RuntimeException('не удалось открыть поток чтения');
        }

        try {
            $ok = $target->writeStream($file, $stream, [
                'ContentType' => $mime,
                // visibility можно не указывать: bucket policy s3:GetObject
                // на * делает объекты читаемыми. Если хотите явный ACL — добавьте:
                // 'visibility' => 'public',
            ]);

            if ($ok === false) {
                throw new \RuntimeException('writeStream вернул false');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Верификация: файл появился и размер совпадает
        if (!$target->exists($file)) {
            throw new \RuntimeException('файл не появился в S3 после записи');
        }

        $tgtSize = $target->size($file);
        if ($tgtSize !== $srcSize) {
            throw new \RuntimeException("размер не совпадает (src={$srcSize}, s3={$tgtSize})");
        }

        return 'moved';
    }

    protected function deleteEmptyDirs(Filesystem $source, string $root): void
    {
        $dirs = $source->allDirectories($root);

        // Сначала самые глубокие
        usort(
            $dirs,
            fn(string $a, string $b) => substr_count($b, '/') <=> substr_count($a, '/'),
        );

        $deleted = 0;

        foreach ($dirs as $dir) {
            if (empty($source->files($dir)) && empty($source->directories($dir))) {
                if ($source->deleteDirectory($dir)) {
                    $deleted++;
                }
            }
        }

        if (empty($source->files($root)) && empty($source->directories($root))) {
            if ($source->deleteDirectory($root)) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->line("Удалено пустых каталогов: {$deleted}");
        }
    }
}
