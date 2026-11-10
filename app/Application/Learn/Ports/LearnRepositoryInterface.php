<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Application\Learn\DTOs\LearnListFilter;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\UserId;
use DateTimeImmutable;

interface LearnRepositoryInterface
{
    /**
     * Сохраняет Learn. Если id === null — INSERT, возвращает копию с присвоенным id.
     * Если id задан — UPDATE.
     */
    public function save(Learn $learn): Learn;

    /**
     * @throws \App\Domain\Learn\Exceptions\LearnNotFound
     */
    public function getById(LearnId $id): Learn;

    /**
     * Прохождение по id, но только если его владелец входит в заданный scope.
     * Нужно для чтения/записи с учётом контекста. null — нет такого ИЛИ вне scope.
     *
     * @param list<int> $visibleUserIds
     */
    public function findByIdWithinScope(LearnId $id, array $visibleUserIds): ?Learn;

    /**
     * Постраничный список прохождений владельцев из scope.
     *
     * @param list<int> $visibleUserIds
     * @return PaginatedResult<Learn>
     */
    public function listWithinScope(array $visibleUserIds, LearnListFilter $filter): PaginatedResult;

    /**
     * Активное прохождение (не терминальное) по паре user+lesson.
     * Нужно для идемпотентности StartLearnAction.
     */
    public function findActiveByUserAndLesson(UserId $userId, LessonId $lessonId): ?Learn;

    /**
     * Ids прохождений в заданном статусе, не архивированных, чья «опорная»
     * дата для этого статуса строго раньше $cutoff.
     *
     * Опорная дата:
     *   graded    → complete_date (fallback status_date)
     *   created   → created_at
     *   scheduled → created_at
     *
     * Нужно для системной архивации (крон).
     *
     * @return list<int>
     */
    public function pluckArchivableIds(
        LearnStatus $status,
        DateTimeImmutable $cutoff,
        int $limit,
    ): array;
}
