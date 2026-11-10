<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\DTOs\StartLearnData;
use App\Application\Learn\Exceptions\LessonNotFound;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\LessonRepositoryInterface;
use App\Application\Learn\Ports\SnapshotResolverInterface;
use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Application\Shared\Clock;
use App\Domain\Learn\Entities\Learn;

final readonly class StartLearnAction
{
    public function __construct(
        private LearnRepositoryInterface $learns,
        private LessonRepositoryInterface $lessons,
        private SnapshotResolverInterface $snapshots,
        private StatusLoggerInterface $statusLogger,
        private Clock $clock,
    ) {}

    public function __invoke(StartLearnData $data): Learn
    {
        // 1. Урок существует и опубликован
        if (!$this->lessons->isPublished($data->lessonId)) {
            throw new LessonNotFound(
                "Lesson {$data->lessonId->value} is not published"
            );
        }

        // 2. Идемпотентность: если есть активное прохождение — вернуть его
        $existing = $this->learns->findActiveByUserAndLesson(
            $data->userId,
            $data->lessonId,
        );
        if ($existing !== null) {
            return $existing;
        }

        // 3. Резолвим снапшот (Redis → S3 → генерация под lock)
        $snapshot = $this->snapshots->ensureFor($data->lessonId);

        // 4. Домен создаёт прохождение
        $learn = Learn::startNow(
            userId: $data->userId,
            lessonId: $data->lessonId,
            snapshot: $snapshot,
            now: $this->clock->now(),
        );

        // 5. Синхронный INSERT. Возвращает Learn с присвоенным id.
        $learn = $this->learns->save($learn);

        // 6. Начальный статус нового прохождения — в очередь через порт.
        //    Домен порождает события только на переходах; здесь фиксируем
        //    первичную запись статуса in_progress.
        $this->statusLogger->log(
            learnId: $learn->id(),
            lessonId: $learn->lessonId(),
            status: $learn->status(),
            statusDate: $learn->statusDate(),
            scheduleDate: $learn->scheduleDate()?->value,
        );

        // 7. Доменные события (если накопятся при дальнейших переходах) —
        //    единообразно уходят во внешние порты.
        foreach ($learn->pullEvents() as $event) {
            $this->statusLogger->log(
                learnId: $event->learnId,
                lessonId: $event->lessonId,
                status: $event->status,
                statusDate: $event->statusDate,
                scheduleDate: $event->scheduleDate?->value,
            );
        }

        return $learn;
    }
}
