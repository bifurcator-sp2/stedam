<?php

declare(strict_types=1);

namespace App\Domain\Learn\Entities;

use App\Domain\Learn\Enums\LearnCategory;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\Events\LearnStatusChanged;
use App\Domain\Learn\Exceptions\InvalidStatusTransition;
use App\Domain\Learn\Exceptions\LearnAlreadyGraded;
use App\Domain\Learn\Exceptions\SnapshotMissing;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LearnAnswers;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Domain\Learn\ValueObjects\UserId;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

final class Learn
{
    /** @var list<LearnStatusChanged> */
    private array $events = [];

    private function __construct(
        private ?LearnId $id,
        private UserId $userId,
        private LessonId $lessonId,
        private LearnStatus $status,
        private ?SnapshotHash $snapshot,
        private ?Grade $grade,
        private ?UserId $gradedBy,
        private ?ScheduleDate $scheduleDate,
        private LearnAnswers $answers,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $archivedAt,
        private DateTimeImmutable $statusDate,
        private ?DateTimeImmutable $completedAt,
    ) {
    }

    // === Фабрики (создание новых прохождений) ===

    /**
     * Моментальное прохождение: ученик сам нажал «Пройти урок».
     * Снапшот обязателен — он создаётся до перехода в in_progress.
     */
    public static function startNow(
        UserId $userId,
        LessonId $lessonId,
        SnapshotHash $snapshot,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: null,
            userId: $userId,
            lessonId: $lessonId,
            status: LearnStatus::InProgress,
            snapshot: $snapshot,
            grade: null,
            gradedBy: null,
            scheduleDate: null,
            answers: LearnAnswers::empty(),
            createdAt: $now,
            archivedAt: null,
            statusDate: $now,
            completedAt: null,
        );
    }

    /**
     * Запланированное прохождение.
     */
    public static function schedule(
        UserId $userId,
        LessonId $lessonId,
        ScheduleDate $scheduleDate,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: null,
            userId: $userId,
            lessonId: $lessonId,
            status: LearnStatus::Scheduled,
            snapshot: null,
            grade: null,
            gradedBy: null,
            scheduleDate: $scheduleDate,
            answers: LearnAnswers::empty(),
            createdAt: $now,
            archivedAt: null,
            statusDate: $now,
            completedAt: null,
        );
    }

    /**
     * Отложенное прохождение: материал курса без даты.
     */
    public static function assign(
        UserId $userId,
        LessonId $lessonId,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: null,
            userId: $userId,
            lessonId: $lessonId,
            status: LearnStatus::Created,
            snapshot: null,
            grade: null,
            gradedBy: null,
            scheduleDate: null,
            answers: LearnAnswers::empty(),
            createdAt: $now,
            archivedAt: null,
            statusDate: $now,
            completedAt: null,
        );
    }

    /**
     * Гидратация из БД. Проверяет согласованность полей.
     * События не порождает — это восстановление, а не изменение.
     */
    public static function reconstitute(
        LearnId $id,
        UserId $userId,
        LessonId $lessonId,
        LearnStatus $status,
        ?SnapshotHash $snapshot,
        ?Grade $grade,
        ?UserId $gradedBy,
        ?ScheduleDate $scheduleDate,
        LearnAnswers $answers,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $archivedAt,
        DateTimeImmutable $statusDate,
        ?DateTimeImmutable $completedAt,
    ): self {
        if ($status === LearnStatus::InProgress && $snapshot === null) {
            throw new SnapshotMissing('Learn in in_progress status must have a snapshot');
        }
        if ($status === LearnStatus::Scheduled && $scheduleDate === null) {
            throw new InvalidStatusTransition('Scheduled learn must have a schedule_date');
        }
        if ($status === LearnStatus::Graded && $grade === null) {
            throw new InvalidStatusTransition('Graded learn must have a grade');
        }

        return new self(
            id: $id,
            userId: $userId,
            lessonId: $lessonId,
            status: $status,
            snapshot: $snapshot,
            grade: $grade,
            gradedBy: $gradedBy,
            scheduleDate: $scheduleDate,
            answers: $answers,
            createdAt: $createdAt,
            archivedAt: $archivedAt,
            statusDate: $statusDate,
            completedAt: $completedAt,
        );
    }

    // === Переходы ===

    public function start(SnapshotHash $snapshot, DateTimeImmutable $now): void
    {
        $this->transitionTo(LearnStatus::InProgress, $now);
        $this->snapshot = $snapshot;
    }

    /**
     * created → scheduled: учитель назначил дату отложенному прохождению.
     */
    public function scheduleAt(ScheduleDate $scheduleDate, DateTimeImmutable $now): void
    {
        $this->transitionTo(LearnStatus::Scheduled, $now);
        $this->scheduleDate = $scheduleDate;
    }

    public function reschedule(ScheduleDate $scheduleDate, DateTimeImmutable $now): void
    {
        if ($this->status !== LearnStatus::Scheduled) {
            throw new InvalidStatusTransition(
                "Only scheduled learns can be rescheduled, got {$this->status->value}"
            );
        }
        $this->scheduleDate = $scheduleDate;
        $this->statusDate = $now;
        $this->recordStatusChange($now);
    }

    public function cancel(DateTimeImmutable $now): void
    {
        $this->transitionTo(LearnStatus::Created, $now);
        $this->scheduleDate = null;
    }

    public function markDone(LearnAnswers $answers, DateTimeImmutable $now): void
    {
        $this->transitionTo(LearnStatus::Done, $now);
        $this->answers = $answers;
        $this->completedAt = $now;
    }

    /**
     * $gradedBy === null означает автоматическую оценку системой.
     */
    public function markGraded(Grade $grade, ?UserId $gradedBy, DateTimeImmutable $now): void
    {
        if ($this->status === LearnStatus::Graded) {
            throw new LearnAlreadyGraded('Learn is already graded');
        }
        $this->transitionTo(LearnStatus::Graded, $now);
        $this->grade = $grade;
        $this->gradedBy = $gradedBy;
    }

    // === Автосохранение ===

    /**
     * Заменяет ответы целиком. Разрешено только в in_progress —
     * по ТЗ автосохранение идёт во время прохождения урока.
     */
    public function updateAnswers(LearnAnswers $answers): void
    {
        if (!$this->status->isEditable()) {
            throw new InvalidStatusTransition(
                "Cannot update answers in status {$this->status->value}"
            );
        }
        $this->answers = $answers;
    }

    // === Архив ===

    /**
     * Помечает прохождение как архивное. Идемпотентно.
     * Не меняет статус — архивация ортогональна жизненному циклу.
     * Разрешено из любого статуса, включая in_progress.
     */
    public function archive(DateTimeImmutable $now): void
    {
        if ($this->archivedAt !== null) {
            return;
        }
        $this->archivedAt = $now;
    }

    /**
     * Извлекает из архива. Идемпотентно.
     */
    public function unarchive(): void
    {
        $this->archivedAt = null;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    // === Категории (для листингов) ===

    public function isAssigned(): bool
    {
        return $this->status === LearnStatus::Created;
    }

    public function isInProgress(): bool
    {
        return $this->status === LearnStatus::InProgress;
    }

    public function isAwaitingGrade(): bool
    {
        return $this->status === LearnStatus::Done;
    }

    public function isGraded(): bool
    {
        return $this->status === LearnStatus::Graded;
    }

    public function isScheduledToday(DateTimeImmutable $now, DateTimeZone $tz): bool
    {
        return $this->status === LearnStatus::Scheduled
            && $this->scheduleDate !== null
            && $this->scheduleDate->isOnLocalDay($now, $tz);
    }

    public function isScheduledFuture(DateTimeImmutable $now, DateTimeZone $tz): bool
    {
        return $this->status === LearnStatus::Scheduled
            && $this->scheduleDate !== null
            && $this->scheduleDate->isAfterLocalDay($now, $tz);
    }

    public function isOverdue(DateTimeImmutable $now, DateTimeZone $tz): bool
    {
        return $this->status === LearnStatus::Scheduled
            && $this->scheduleDate !== null
            && $this->scheduleDate->isBeforeLocalDay($now, $tz);
    }

    public function category(DateTimeImmutable $now, DateTimeZone $tz): LearnCategory
    {
        return match ($this->status) {
            LearnStatus::Created    => LearnCategory::Assigned,
            LearnStatus::InProgress => LearnCategory::InProgress,
            LearnStatus::Done       => LearnCategory::AwaitingGrade,
            LearnStatus::Graded     => LearnCategory::Graded,
            LearnStatus::Scheduled  => $this->scheduledCategory($now, $tz),
        };
    }

    private function scheduledCategory(DateTimeImmutable $now, DateTimeZone $tz): LearnCategory
    {
        if ($this->scheduleDate === null) {
            throw new LogicException(
                'Scheduled learn without schedule_date is not possible due to reconstitute invariant'
            );
        }

        if ($this->scheduleDate->isBeforeLocalDay($now, $tz)) {
            return LearnCategory::Overdue;
        }
        if ($this->scheduleDate->isOnLocalDay($now, $tz)) {
            return LearnCategory::ScheduledToday;
        }

        return LearnCategory::ScheduledFuture;
    }

    // === Доменные события ===

    /**
     * Отдаёт и очищает накопленные события.
     * Application-слой вызывает после сохранения агрегата,
     * чтобы перевести их во внешние порты (StatusLoggerInterface и т.п.).
     *
     * @return list<LearnStatusChanged>
     */
    public function pullEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    private function recordStatusChange(DateTimeImmutable $now): void
    {
        // Событие осмысленно только для уже сохранённого агрегата:
        // у нового Learn ещё нет id, и логгер некуда писать.
        if ($this->id === null) {
            return;
        }

        $this->events[] = new LearnStatusChanged(
            learnId: $this->id,
            lessonId: $this->lessonId,
            status: $this->status,
            statusDate: $now,
            scheduleDate: $this->scheduleDate,
        );
    }

    // === Внутренние инварианты ===

    private function transitionTo(LearnStatus $target, DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new InvalidStatusTransition(
                "Cannot transition from {$this->status->value} to {$target->value}"
            );
        }
        $this->status = $target;
        $this->statusDate = $now;
        $this->recordStatusChange($now);
    }

    // === Идентичность ===

    public function withId(LearnId $id): self
    {
        if ($this->id !== null) {
            throw new LogicException('Learn already has an id');
        }
        $clone = clone $this;
        $clone->id = $id;
        // События накапливаются только у агрегата с id; при клонировании
        // у нового агрегата их ещё нет, но сбрасываем на случай повторного withId.
        $clone->events = [];

        return $clone;
    }

    // === Геттеры ===

    public function id(): ?LearnId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function lessonId(): LessonId
    {
        return $this->lessonId;
    }

    public function status(): LearnStatus
    {
        return $this->status;
    }

    public function snapshot(): ?SnapshotHash
    {
        return $this->snapshot;
    }

    public function grade(): ?Grade
    {
        return $this->grade;
    }

    public function gradedBy(): ?UserId
    {
        return $this->gradedBy;
    }

    public function scheduleDate(): ?ScheduleDate
    {
        return $this->scheduleDate;
    }

    public function answers(): LearnAnswers
    {
        return $this->answers;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function archivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function statusDate(): DateTimeImmutable
    {
        return $this->statusDate;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }
}
