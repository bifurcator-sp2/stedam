<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Learn\LearnStatusBuffer;
use App\Jobs\FlushLearnStatusBufferJob;
use Illuminate\Console\Command;

/**
 * Сбрасывает буфер статусов (Redis) в БД.
 *
 * По умолчанию — ставит job в очередь. С --sync — выполняет сразу
 * (полезно для отладки / когда воркеры не запущены).
 */
class FlushLearnStatuses extends Command
{
    protected $signature = 'learn:flush-statuses
                            {--sync : Выполнить синхронно, без очереди}
                            {--max= : Максимум событий за прогон}';

    protected $description = 'Сбрасывает буфер смен статусов прохождений в learn_statuses';

    public function handle(LearnStatusBuffer $buffer): int
    {
        if (config('learn.status_logger.driver') !== 'redis') {
            $this->warn('Status logger не в redis-режиме — сбрасывать нечего.');
            return self::SUCCESS;
        }

        $size = $buffer->size();
        $this->info("В буфере: {$size} событий.");

        if ($size === 0) {
            return self::SUCCESS;
        }

        $max = $this->option('max');
        $max = $max === null ? null : (int) $max;

        $job = new FlushLearnStatusBufferJob($max);

        if ($this->option('sync')) {
            dispatch_sync($job);
            $this->info('Сброшено синхронно.');
        } else {
            dispatch($job);
            $this->info('Задача сброса поставлена в очередь.');
        }

        return self::SUCCESS;
    }
}
