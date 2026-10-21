<?php
// app/Console/Commands/PruneOrphans.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneOrphans extends Command
{
    protected $signature = 'files:prune
                            {--model=blocks : Владелец (blocks, lessons, ...) или file-sets для всех FileSet-ов}
                            {--chunk=100 : Размер чанка}';

    protected $description = 'Удаляет файлы-сироты и пустые папки у FileSet-ов';

    public function handle(): int
    {
        $modelName = (string) $this->option('model');
        $chunkSize = max(1, (int) $this->option('chunk'));

        // ---------- Резолв класса FileSet ----------
        $fileSetClass = config('file-sets.file_set_model');

        if (!is_string($fileSetClass) || !class_exists($fileSetClass)) {
            $this->error("Не найден класс file_set_model из config('file-sets').");
            return self::FAILURE;
        }

        // ---------- Резолв фильтра по владельцу ----------
        $normalized = strtolower(str_replace(['-', '_'], '', $modelName));
        $allFileSets = in_array($normalized, ['', 'fileset', 'filesets'], true);

        $ownerClass = null;

        if (!$allFileSets) {
            $ownerClass = 'App\\Models\\' . str($modelName)->studly()->singular()->toString();

            if (!class_exists($ownerClass)) {
                $this->error("Модель-владелец не найдена: {$ownerClass}");
                return self::FAILURE;
            }

            $this->info("FileSet'ы владельца {$ownerClass}");
        } else {
            $this->info('Все FileSet\'ы');
        }

        // ---------- Подсчёт ----------
        // withTrashed() нужен, чтобы подчистить папки у soft-deleted записей.
        // Если у модели нет SoftDeletes, withTrashed просто проигнорируется.
        $baseQuery = $fileSetClass::withTrashed();
        if ($ownerClass !== null) {
            $baseQuery->where('model', $ownerClass);
        }

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            $this->warn('Нечего обходить в БД: 0 FileSet-ов по заданному фильтру.');
        } else {
            $this->info("К обработке в БД: {$total}");

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $deletedFiles = 0;
            $deletedDirs  = 0;
            $processed    = 0;
            $skipped      = 0;
            $errors       = 0;

            $baseQuery->chunkById($chunkSize, function ($fileSets) use (
                &$deletedFiles, &$deletedDirs, &$processed, &$skipped, &$errors, $bar
            ) {
                foreach ($fileSets as $fileSet) {
                    if (!method_exists($fileSet, 'pruneOrphans')) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    try {
                        // 1. Файлы-сироты
                        $orphanReport = $fileSet->pruneOrphans();
                        $orphanCount  = count($orphanReport['images']['deleted'])
                            + count($orphanReport['files']['deleted']);

                        // 2. Пустые папки (включая шард-родителей)
                        $dirCount = 0;
                        if (method_exists($fileSet, 'pruneEmptyStoreDirs')) {
                            $dirReport = $fileSet->pruneEmptyStoreDirs();
                            $dirCount  = count($dirReport['deleted']);
                        }

                        if ($orphanCount > 0 || $dirCount > 0) {
                            $details = [];
                            if ($orphanCount > 0) $details[] = "файлов {$orphanCount}";
                            if ($dirCount > 0)    $details[] = "папок {$dirCount}";

                            $bar->clear();
                            $this->newLine();
                            $this->line("  FileSet #{$fileSet->getKey()}: " . implode(', ', $details));
                            $bar->display();
                        }

                        $deletedFiles += $orphanCount;
                        $deletedDirs  += $dirCount;
                        $processed++;
                    } catch (\Throwable $e) {
                        $errors++;

                        $bar->clear();
                        $this->newLine();
                        $this->warn("  FileSet #{$fileSet->getKey()}: {$e->getMessage()}");
                        $bar->display();
                    }

                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine(2);

            $this->info("Обработано FileSet-ов: {$processed}");
            if ($errors > 0) $this->warn("Ошибок: {$errors}");
            if ($skipped > 0) $this->warn("Пропущено: {$skipped}");

            $this->info("Всего удалено файлов (из БД): {$deletedFiles}");
            $this->info("Всего удалено пустых папок (из БД): {$deletedDirs}");
        }

        // ---------- Глобальная зачистка пустых папок ----------
        // Удаляет папки, которых нет в БД (например, если запись удалили руками)
        $this->cleanupEmptyDirs();

        return self::SUCCESS;
    }

    protected function cleanupEmptyDirs(): void
    {
        $storeDisk = config('file-sets.store_disk', 'public');
        $storage   = Storage::disk($storeDisk);
        $baseDir   = 'models/file_sets';

        if (!$storage->exists($baseDir)) {
            return;
        }

        $deleted = [];
        $failed  = [];

        $this->scanAndPrune($storage, $baseDir, $deleted, $failed);

        if (count($deleted) > 0) {
            $this->newLine();
            $this->info("Удалено пустых папок (глобальный скан диска): " . count($deleted));
            foreach ($deleted as $dir) {
                $this->line("  - {$dir}");
            }
        }

        if (count($failed) > 0) {
            $this->newLine();
            $this->warn("Не удалось удалить следующие папки:");
            foreach ($failed as $dir => $reason) {
                $this->warn("  - {$dir}");
                $this->line("    Причина: {$reason}");
            }
        }
    }

    protected function scanAndPrune($storage, string $dir, array &$deleted, array &$failed): bool
    {
        $isEmpty = true;

        // 1. Рекурсивно обходим дочерние папки
        $subDirs = $storage->directories($dir);
        foreach ($subDirs as $subDir) {
            if (!$this->scanAndPrune($storage, $subDir, $deleted, $failed)) {
                $isEmpty = false;
            }
        }

        // 2. Проверяем наличие файлов (стандартным способом)
        if (count($storage->files($dir)) > 0) {
            $isEmpty = false;
        }

        // 3. Если папка пуста и это не корень — пробуем удалить
        if ($isEmpty && $dir !== 'models/file_sets') {
            try {
                $success = $storage->deleteDirectory($dir);

                if ($success) {
                    $deleted[] = $dir;
                    return true;
                }

                // Если deleteDirectory не сработал — пробуем форсированно
                if (method_exists($storage, 'path')) {
                    $absolutePath = $storage->path($dir);
                    $forceResult = $this->forceDeleteDirectory($absolutePath);

                    if ($forceResult === true) {
                        $deleted[] = $dir;
                        return true;
                    } else {
                        $failed[$dir] = $forceResult;
                        return false;
                    }
                } else {
                    $failed[$dir] = 'deleteDirectory вернул false (удалённый диск?)';
                    return false;
                }

            } catch (\Throwable $e) {
                $failed[$dir] = $e->getMessage();
                return false;
            }
        }

        return $isEmpty;
    }

    /**
     * Принудительное удаление папки со всем содержимым.
     * Возвращает true при успехе или строку с причиной ошибки.
     */
    protected function forceDeleteDirectory(string $path): string|bool
    {
        if (is_link($path)) {
            return @unlink($path) ? true : "Не удалось удалить симлинк";
        }

        if (!is_dir($path)) {
            return @unlink($path) ? true : "Не удалось удалить файл";
        }

        // Сканируем ВСЁ, включая скрытые файлы
        $items = @scandir($path);
        if ($items === false) {
            return "Нет прав на чтение директории";
        }

        $hiddenFiles = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $subPath = $path . DIRECTORY_SEPARATOR . $item;

            // Собираем скрытые файлы для отчёта
            if (str_starts_with($item, '.')) {
                $hiddenFiles[] = $item;
            }

            $res = $this->forceDeleteDirectory($subPath);
            if ($res !== true) {
                return "Ошибка в [" . basename($subPath) . "]: " . $res;
            }
        }

        // Если не удалось удалить саму папку
        if (!@rmdir($path)) {
            $error = error_get_last()['message'] ?? 'Неизвестная ошибка rmdir';
            if (!empty($hiddenFiles)) {
                $error .= ' (обнаружены скрытые файлы: ' . implode(', ', $hiddenFiles) . ')';
            } else {
                $error .= ' (скорее всего, проблема с правами доступа к папке)';
            }
            return $error;
        }

        return true;
    }
}
