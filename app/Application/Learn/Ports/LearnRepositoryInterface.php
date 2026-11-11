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
     * Если id задан — UPDATE; отсутствие строки — доменная ошибка LearnNotFound.
     */
    public function save(Learn $learn): Learn;

    /**
     * INSERT прохождения с id, предвыделенным reserveId().
     *
     * Отдельный метод (а не «save угадает»), потому что «строка есть / строки
     * нет» здесь определяет вызывающий код: сценарий старта знает, что
     * прохождение новое, и что id взяли из последовательности заранее.
     *
     * @throws \LogicException если id у агрегата не задан
     */
    public function insert(Learn $learn): Learn;

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
     * Резервирует id из последовательности learns (SELECT nextval) БЕЗ INSERT.
     *
     * Нужен для генерации questions с сидом по learnId ДО сохранения:
     * сид = f(userId, learnId, blockIndex), значит learnId должен быть
     * известен до того, как агрегат получает строку в БД.
     * На этом id либо затем делается INSERT с withId(), либо он теряется
     * (пропуски в последовательности безопасны).
     */
    public function reserveId(): LearnId;

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
