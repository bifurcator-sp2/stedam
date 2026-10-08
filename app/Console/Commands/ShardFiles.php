<?php
// app/Console/Commands/ShardFiles.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ShardFiles extends Command
{
    protected $signature = 'files:shard
                            {--model=blocks : Имя таблицы/модели (например, blocks)}
                            {--dry-run : Только показать, что будет сделано}';

    protected $description = 'Переносит файлы из плоской структуры в шардированную';

    public function handle(): int
    {
        $modelName = $this->option('model');
        $dryRun = (bool) $this->option('dry-run');

        $class = 'App\\Models\\' . str($modelName)->studly()->singular()->toString();

        if (!class_exists($class)) {
            $this->error("Model class not found: {$class}");
            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $oldRoot = "models/{$modelName}";

        if (!$disk->exists($oldRoot)) {
            $this->info("Nothing to migrate: {$oldRoot} not found");
            return self::SUCCESS;
        }

        $directories = $disk->directories($oldRoot);
        $moved = 0;
        $skipped = 0;

        foreach ($directories as $oldDir) {
            $id = basename($oldDir);

            // Пропускаем уже шардированные (в пути есть "/", а не просто цифры)
            if (!ctype_digit($id)) {
                continue;
            }

            $model = $class::find((int) $id);

            if (!$model) {
                $this->warn("Model #{$id} not found, skipping");
                $skipped++;
                continue;
            }

            $newDir = $model->storeDir();

            // Уже в новом месте — нечего делать
            if ($oldDir === $newDir) {
                continue;
            }

            $this->line("Move: {$oldDir} → {$newDir}");

            if ($dryRun) {
                $moved++;
                continue;
            }

            $disk->makeDirectory($newDir);

            foreach ($disk->files($oldDir) as $file) {
                $filename = basename($file);
                $disk->move($file, "{$newDir}/{$filename}");
            }

            // Удаляем только если папка пустая
            $remaining = $disk->files($oldDir);
            if (empty($remaining)) {
                $disk->deleteDirectory($oldDir);
            } else {
                $this->warn("Old dir not empty: {$oldDir}");
            }

            $moved++;
        }

        $this->info("Done. Moved: {$moved}, skipped: {$skipped}");

        if ($dryRun) {
            $this->info('This was a dry-run. Nothing actually changed.');
        }

        return self::SUCCESS;
    }
}
