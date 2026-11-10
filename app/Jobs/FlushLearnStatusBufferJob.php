<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Learn\Ports\StatusBufferInterface;
use App\Models\LearnStatus as LearnStatusModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Сбрасывает накопленный в Redis буфер смен статусов в learn_statuses батчем.
 *
 * Перезапускаемый: если упадёт между LPOP и INSERT — часть событий потеряется
 * (буфер уже вынут). Для строгой гарантии нужен BRPOPLPUSH в processing-list;
 * здесь выбран простой вариант: события «best effort», они аудитные,
 * источник правды по текущему статусу — строка learns.
 *
 * Чтобы минимизировать окно: сначала INSERT, затем считаем успех; при ошибке
 * исключение уводит job в failed (мониторинг), данные уже изъяты из буфера.
 */
final class FlushLearnStatusBufferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public ?int $maxEvents = null,
    ) {}

    public function handle(StatusBufferInterface $buffer): void
    {
        $chunk = $this->maxEvents ?? (int) config('learn.status_logger.flush_chunk', 1000);

        $remaining = $chunk;

        while ($remaining > 0) {
            $events = $buffer->pop(min($remaining, 1000));
            if ($events === []) {
                break;
            }

            $rows = [];
            foreach ($events as $event) {
                $rows[] = [
                    'learn_id'      => (int) $event['learn_id'],
                    'lesson_id'     => (int) $event['lesson_id'],
                    'status'        => (string) $event['status'],
                    'status_date'   => $event['status_date'],
                    'schedule_date' => $event['schedule_date'] ?? null,
                    'created_at'    => now(),
                ];
            }

            LearnStatusModel::query()->insert($rows);

            $remaining -= count($events);
        }
    }
}
