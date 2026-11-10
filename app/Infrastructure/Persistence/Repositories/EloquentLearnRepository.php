<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\Learn\DTOs\LearnListFilter;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Learn\Entities\Learn as LearnEntity;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LearnAnswers;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Domain\Learn\ValueObjects\UserId;
use App\Models\Learn as LearnModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Eloquent-реализация репозитория Прохождений.
 *
 * Единственное место, где доменная сущность ↔ строки БД.
 * Домен не знает о Eloquent; Application — о маппинге колонок.
 */
final class EloquentLearnRepository implements LearnRepositoryInterface
{
    private DateTimeZone $tz;

    public function __construct()
    {
        $this->tz = new DateTimeZone('UTC');
    }

    public function save(LearnEntity $learn): LearnEntity
    {
        $model = $learn->id() === null
            ? new LearnModel()
            : LearnModel::query()->findOrFail($learn->id()->value);

        $model->user_id         = $learn->userId()->value;
        $model->lesson_id       = $learn->lessonId()->value;
        $model->lesson_snapshot = $learn->snapshot()?->value;
        $model->status          = $learn->status();
        $model->status_date     = $learn->statusDate();
        $model->schedule_date   = $learn->scheduleDate()?->value;
        $model->score           = $learn->grade()?->value;
        $model->graded_by       = $learn->gradedBy()?->value;
        $model->answers         = $learn->answers()->toArray();
        $model->complete_date   = $learn->completedAt();
        $model->archived_at     = $learn->archivedAt();

        $model->save();

        return $learn->id() === null
            ? $learn->withId(new LearnId($model->id))
            : $learn;
    }

    public function getById(LearnId $id): LearnEntity
    {
        $model = LearnModel::query()->find($id->value);

        if ($model === null) {
            throw new LearnNotFound("Learn {$id->value} not found");
        }

        return $this->toEntity($model);
    }

    public function findByIdWithinScope(LearnId $id, array $visibleUserIds): ?LearnEntity
    {
        if ($visibleUserIds === []) {
            return null;
        }

        $model = LearnModel::query()
            ->where('id', $id->value)
            ->whereIn('user_id', $visibleUserIds)
            ->first();

        return $model === null ? null : $this->toEntity($model);
    }

    public function listWithinScope(array $visibleUserIds, LearnListFilter $filter): PaginatedResult
    {
        $query = LearnModel::query()
            ->whereIn('user_id', $visibleUserIds)
            ->orderByDesc('id');

        if (!$filter->includeArchived) {
            $query->whereNull('archived_at');
        }

        if ($filter->statuses !== []) {
            $query->whereIn(
                'status',
                array_map(static fn (LearnStatus $s): string => $s->value, $filter->statuses),
            );
        }

        if ($filter->lessonId !== null) {
            $query->where('lesson_id', $filter->lessonId->value);
        }

        if ($filter->dateFrom !== null) {
            $query->where('created_at', '>=', $filter->dateFrom);
        }

        if ($filter->dateTo !== null) {
            $query->where('created_at', '<=', $filter->dateTo);
        }

        $perPage = max(1, min($filter->perPage, 100));
        $page = max(1, $filter->page);

        $total = (clone $query)->count();
        $models = $query->forPage($page, $perPage)->get();

        return new PaginatedResult(
            items: array_map(fn (LearnModel $m): LearnEntity => $this->toEntity($m), $models->all()),
            total: $total,
            page: $page,
            perPage: $perPage,
        );
    }

    public function findActiveByUserAndLesson(UserId $userId, LessonId $lessonId): ?LearnEntity
    {
        $model = LearnModel::query()
            ->where('user_id', $userId->value)
            ->where('lesson_id', $lessonId->value)
            ->whereNotIn('status', [LearnStatus::Graded->value])
            ->orderByDesc('id')
            ->first();

        return $model === null ? null : $this->toEntity($model);
    }

    public function pluckArchivableIds(
        LearnStatus $status,
        \DateTimeImmutable $cutoff,
        int $limit,
    ): array {
        $query = LearnModel::query()
            ->where('status', $status->value)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->limit(max(1, $limit));

        // Опорная дата зависит от статуса — фильтруем по «правильной» колонке.
        if ($status === LearnStatus::Graded) {
            // graded: complete_date, а если пусто — status_date.
            $query->where(function ($q) use ($cutoff) {
                $q->where('complete_date', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('complete_date')
                            ->where('status_date', '<', $cutoff);
                    });
            });
        } else {
            // created / scheduled: created_at.
            $query->where('created_at', '<', $cutoff);
        }

        return $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    // ==================== Маппинг ====================

    private function toEntity(LearnModel $model): LearnEntity
    {
        return LearnEntity::reconstitute(
            id: new LearnId($model->id),
            userId: new UserId($model->user_id),
            lessonId: new LessonId($model->lesson_id),
            status: $model->status,
            snapshot: $model->lesson_snapshot !== null
                ? new SnapshotHash($model->lesson_snapshot)
                : null,
            grade: $model->score !== null
                ? new Grade((float) $model->score)
                : null,
            gradedBy: $model->graded_by !== null
                ? new UserId($model->graded_by)
                : null,
            scheduleDate: $model->schedule_date !== null
                ? new ScheduleDate($this->toImmutable($model->schedule_date))
                : null,
            answers: LearnAnswers::fromArray($model->answers ?? []),
            createdAt: $this->toImmutable($model->created_at),
            archivedAt: $model->archived_at !== null
                ? $this->toImmutable($model->archived_at)
                : null,
            statusDate: $this->toImmutable($model->status_date ?? $model->created_at),
            completedAt: $model->complete_date !== null
                ? $this->toImmutable($model->complete_date)
                : null,
        );
    }

    private function toImmutable(\DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone($this->tz);
    }
}
