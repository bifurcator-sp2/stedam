<?php

declare(strict_types=1);

namespace App\Application\Lessons\Ports;

use App\Domain\Lessons\Entities\Lesson;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * Репозиторий уроков.
 *
 * save()    — INSERT/UPDATE самой строки урока + синхронизация таксономий.
 * getById() — по id; отсутствие — доменная ошибка LessonNotFound.
 * findById()— nullable-вариант.
 *
 * Репозиторий НЕ управляет блоками: это забота LessonBlockRepositoryInterface.
 * Публичный API между агрегатами — только через id.
 */
interface LessonRepositoryInterface
{
    /**
     * Сохраняет урок. id === null → INSERT (возвращает копию с id).
     * id задан → UPDATE; отсутствие строки — LessonNotFound.
     */
    public function save(Lesson $lesson): Lesson;

    public function getById(LessonId $id): Lesson;

    public function findById(LessonId $id): ?Lesson;

    /**
     * Владелец урока (user_id). Нужен для проверки прав из Application
     * без полной загрузки урока.
     *
     * null — нет такого урока.
     */
    public function findOwnerId(LessonId $id): ?UserId;

    /**
     * Список таксономий урока.
     *
     * @return list<int>
     */
    public function taxonomyIdsOf(LessonId $id): array;

    /**
     * Потомки (включая сами узлы) для набора таксономий.
     * Нужно для LessonRulesChecker: у задания может быть более
     * специфичная таксономия, чем у урока.
     *
     * @param list<int> $taxonomyIds
     * @return list<int>
     */
    public function expandTaxonomiesWithDescendants(array $taxonomyIds): array;

    /**
     * id-шники уроков, где урок — владелец, для проверок.
     *
     * @param list<int> $lessonIds
     * @return list<int>
     */
    public function pluckUserOwnedLessonIds(UserId $userId, array $lessonIds): array;

    // ===== Dev-/seeding-операции =====
    //
    // Используются консольными командами пересборки домена
    // (SeedLessonsDomainCommand и т.п.). Это не часть публичного
    // HTTP-API, но и не «сырой SQL»: действия остаются в доменном
    // словаре (LessonId) и проходят через репозиторий.

    /**
     * Все id уроков в БД (включая soft-deleted). Для массового reseed.
     *
     * @return list<int>
     */
    public function pluckAllIds(): array;

    /**
     * Удалить урок по id вместе с собственными блоками и привязками.
     * Отсутствие урока — не ошибка (idempotent delete).
     */
    public function delete(LessonId $id): void;
}
