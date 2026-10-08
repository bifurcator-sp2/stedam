<?php
// app/Console/Commands/CleanTempFiles.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanTempFiles extends Command
{
    protected $signature = 'files:clean-temp
                            {--hours=24 : Удалять temp-файлы старше N часов}
                            {--dry-run : Только показать, что будет удалено}';

    protected $description = 'Удаляет старые файлы из temp-хранилища';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');

        if ($hours <= 0) {
            $this->error('Параметр --hours должен быть больше 0');
            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $tempRoot = 'temp';

        if (!$disk->exists($tempRoot)) {
            $this->info('temp не существует — нечего чистить');
            return self::SUCCESS;
        }

        $threshold = now()->subHours($hours)->getTimestamp();

        $deletedDirs = 0;
        $deletedFiles = 0;
        $scannedDirs = 0;

        // temp/users/{uid}/models/{table}/{id}/
        // Сканируем рекурсивно до 4-го уровня
        foreach ($disk->directories($tempRoot) as $usersDir) {
            foreach ($disk->directories($usersDir) as $modelsDir) {
                foreach ($disk->directories($modelsDir) as $tableDir) {
                    foreach ($disk->directories($tableDir) as $idDir) {
                        $scannedDirs++;

                        $mtime = $this->dirMtime($disk, $idDir);

                        if ($mtime === null) {
                            continue;
                        }

                        if ($mtime >= $threshold) {
                            continue;
                        }

                        $files = $disk->files($idDir);

                        if ($dryRun) {
                            $this->line("[dry] delete: {$idDir} ({$mtime})");
                            $deletedDirs++;
                            $deletedFiles += count($files);
                            continue;
                        }

                        $this->line("Delete: {$idDir} (files: " . count($files) . ')');

                        // Удаляем все файлы и thumb_*
                        foreach ($files as $file) {
                            $disk->delete($file);
                            $deletedFiles++;
                        }

                        // Удаляем саму папку
                        if ($disk->directoryExists($idDir)) {
                            $disk->deleteDirectory($idDir);
                            $deletedDirs++;
                        }
                    }
                }
            }
        }

        $this->info("Scanned: {$scannedDirs}, deleted dirs: {$deletedDirs}, files: {$deletedFiles}");

        if ($dryRun) {
            $this->info('This was a dry-run. Nothing actually changed.');
        }

        return self::SUCCESS;
    }

    /**
     * Возвращает mtime папки — самый «свежий» mtime среди её файлов.
     * Так папка не удалится, если в неё недавно что-то доложили.
     */
    protected function dirMtime($disk, string $dir): ?int
    {
        $files = $disk->files($dir);

        if (empty($files)) {
            // Пустая папка — используем lastModified самой директории
            try {
                return $disk->lastModified($dir);
            } catch (\Throwable) {
                return null;
            }
        }

        $max = 0;
        foreach ($files as $file) {
            try {
                $m = $disk->lastModified($file);
                if ($m > $max) $max = $m;
            } catch (\Throwable) {
                // ignore
            }
        }

        return $max > 0 ? $max : null;
    }
}
