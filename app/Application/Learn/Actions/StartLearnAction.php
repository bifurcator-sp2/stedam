<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\StartLearnData;
use App\Application\Learn\Exceptions\LearnAccessDenied;
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
        private ContextResolverInterface $contextResolver,
        private Clock $clock,
    ) {}

    public function __invoke(StartLearnData $data): Learn
    {
        // 1. Право на прохождение — по контексту смотрящего.
        $context = $this->contextResolver->resolve($data->userId);
        if (!$context->canStart) {
            throw new LearnAccessDenied('You are not allowed to start a learn');
        }

        // 2. Урок существует и опубликован
        if (!$this->lessons->isPublished($data->lessonId)) {
            throw new LessonNotFound(
                "Lesson {$data->lessonId->value} is not published"
            );
        }

        $now = $this->clock->now();

        // 3. Идемпотентность: если есть активное прохождение — работать с ним.
        $existing = $this->learns->findActiveByUserAndLesson(
            $data->userId,
            $data->lessonId,
        );
        if ($existing !== null) {
            // Уже идёт — повторный старт ничего не меняет.
            if ($existing->isInProgress()) {
                return $existing;
            }

            // created/scheduled → старт: пишем снапшот и фактическое время.
            // scheduleDate сохраняется — по нему считается отклонение от плана.
            $existing->start($this->snapshots->ensureFor($data->lessonId), $now);

            $existing = $this->learns->save($existing);
            $this->drainEvents($existing);

            return $existing;
        }

        // 4. Резолвим снапшот (Redis → S3 → генерация под lock)
        $snapshot = $this->snapshots->ensureFor($data->lessonId);

        // 5. Домен создаёт прохождение
        $learn = Learn::startNow(
            userId: $data->userId,
            lessonId: $data->lessonId,
            snapshot: $snapshot,
            now: $now,
        );

        // 6. Синхронный INSERT. Возвращает Learn с присвоенным id.
        $learn = $this->learns->save($learn);

        // 7. Начальный статус нового прохождения — в очередь через порт.
        //    Домен порождает события только на переходах; здесь фиксируем
        //    первичную запись статуса in_progress.
        $this->statusLogger->log(
            learnId: $learn->id(),
            lessonId: $learn->lessonId(),
            status: $learn->status(),
            statusDate: $learn->statusDate(),
            scheduleDate: $learn->scheduleDate()?->value,
        );

        // 8. Доменные события — единообразно во внешние порты.
        $this->drainEvents($learn);

        return $learn;
    }

    private function drainEvents(Learn $learn): void
    {
        foreach ($learn->pullEvents() as $event) {
            $this->statusLogger->log(
                learnId: $event->learnId,
                lessonId: $event->lessonId,
                status: $event->status,
                statusDate: $event->statusDate,
                scheduleDate: $event->scheduleDate?->value,
            );
        }
    }
}
