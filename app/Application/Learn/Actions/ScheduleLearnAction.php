<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\ScheduleLearnData;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Exceptions\LessonNotFound;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\LessonRepositoryInterface;
use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Application\Shared\Clock;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\Exceptions\InvalidStatusTransition;
use App\Domain\Learn\ValueObjects\ScheduleDate;

/**
 * Учитель назначает/планирует урок ученику.
 *
 * Поведение (H):
 *   - нет прохождения            → создаём (scheduled, если есть дата; created, если нет);
 *   - существует 'created'        → schedule: created → scheduled (reschedule без даты невозможен);
 *   - существует 'scheduled'      → перепланирование (reschedule);
 *   - in_progress/done/graded     → ошибка (нельзя перепланировать идущее/завершённое).
 *
 * scheduleDate === null трактуется как «assign» (created).
 */
final readonly class ScheduleLearnAction
{
    public function __construct(
        private LearnRepositoryInterface $learns,
        private LessonRepositoryInterface $lessons,
        private ContextResolverInterface $contextResolver,
        private StatusLoggerInterface $statusLogger,
        private Clock $clock,
    ) {}

    public function __invoke(ScheduleLearnData $data): Learn
    {
        $context = $this->contextResolver->resolve($data->viewerId);

        if (!$context->canSchedule || !$context->canAssign) {
            throw new LearnAccessDenied('You are not allowed to assign/schedule');
        }

        // Целевой ученик обязан быть в scope учителя.
        if (!$context->canSeeUser($data->targetUserId->value)) {
            throw new LearnAccessDenied('Target user is not in your scope');
        }

        if (!$this->lessons->isPublished($data->lessonId)) {
            throw new LessonNotFound("Lesson {$data->lessonId->value} is not published");
        }

        $now = $this->clock->now();
        $existing = $this->learns->findActiveByUserAndLesson($data->targetUserId, $data->lessonId);

        // 1. Новое прохождение.
        if ($existing === null) {
            $learn = $data->scheduleDate !== null
                ? Learn::schedule($data->targetUserId, $data->lessonId, $data->scheduleDate, $now)
                : Learn::assign($data->targetUserId, $data->lessonId, $now);

            $learn = $this->learns->save($learn);
            $this->logInitial($learn);

            return $learn;
        }

        // 2. Существующее: только открытые статусы можно (пере)планировать.
        switch ($existing->status()) {
            case LearnStatus::Scheduled:
                if ($data->scheduleDate === null) {
                    throw new InvalidStatusTransition(
                        'Cannot assign an already scheduled learn without a date'
                    );
                }
                $existing->reschedule($data->scheduleDate, $now);
                break;

            case LearnStatus::Created:
                if ($data->scheduleDate === null) {
                    // assign на уже назначенное — no-op (идемпотентность).
                    return $existing;
                }
                $existing->scheduleAt($data->scheduleDate, $now);
                break;

            default:
                throw new InvalidStatusTransition(
                    "Cannot schedule learn in status {$existing->status()->value}"
                );
        }

        $existing = $this->learns->save($existing);
        $this->drainEvents($existing);

        return $existing;
    }

    private function logInitial(Learn $learn): void
    {
        $this->statusLogger->log(
            learnId: $learn->id(),
            lessonId: $learn->lessonId(),
            status: $learn->status(),
            statusDate: $learn->statusDate(),
            scheduleDate: $learn->scheduleDate()?->value,
        );
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
