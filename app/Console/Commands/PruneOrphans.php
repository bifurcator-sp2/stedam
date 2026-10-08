<?php
// app/Console/Commands/PruneOrphans.php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneOrphans extends Command
{
    protected $signature = 'files:prune
                            {--model=blocks : Имя таблицы/модели}
                            {--chunk=100 : Размер чанка}';

    protected $description = 'Удаляет сироты (файлы без записи в реестре) у моделей';

    public function handle(): int
    {
        $modelName = $this->option('model');
        $chunkSize = (int) $this->option('chunk');

        $class = 'App\\Models\\' . str($modelName)->studly()->singular()->toString();

        if (!class_exists($class)) {
            $this->error("Model class not found: {$class}");
            return self::FAILURE;
        }

        $total = 0;

        $class::query()
            ->chunkById($chunkSize, function ($models) use (&$total) {
                foreach ($models as $model) {
                    if (!method_exists($model, 'pruneOrphans')) {
                        continue;
                    }

                    $report = $model->pruneOrphans();
                    $deleted = count($report['images']['deleted']) + count($report['files']['deleted']);

                    if ($deleted > 0) {
                        $this->line("Model #{$model->getKey()}: deleted {$deleted}");
                        $total += $deleted;
                    }
                }
            });

        $this->info("Total deleted: {$total}");
        return self::SUCCESS;
    }
}
