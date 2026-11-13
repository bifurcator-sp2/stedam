<?php

declare(strict_types=1);

namespace App\Application\Course\Ports;

use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Репозиторий агрегата Course.
 *
 * Состав (темы + уроки) сохраняется целиком: агрегат в памяти уже
 * нормализован (order 1..N без дыр), репозиторий просто переносит его в БД
 * в одной транзакции.
 */
interface CourseRepositoryInterface
{
    /**
     * Загрузить курс со всем составом. null — не найден.
     */
    public function findById(CourseId $courseId): ?Course;

    /**
     * Сохранить агрегат. Возвращает курс с проставленными id
     * (курса, новых тем, новых уроков).
     */
    public function save(Course $course): Course;

    /**
     * Удалить курс (soft delete на уровне БД, если колонка есть).
     */
    public function delete(CourseId $courseId): void;

    /**
     * Листинг для смотрящего (§13.2).
     *
     * $ownerId — чьи курсы показывать:
     *   - UserId  → курсы этого владельца (+ системные, если $includeSystem);
     *   - null    → без фильтра по владельцу: только системные, если
     *               $includeSystem, иначе пусто.
     *
     * «Показать всё без ограничений» (admin/methodologist/headteacher)
     * выражается парой $ownerId = null + $allOwners = true. Без второго
     * флага null означал бы «только системные» и админ не видел бы чужих
     * курсов.
     *
     * @param bool $allOwners снять фильтр по владельцу полностью
     * @return list<Course>
     */
    public function listForOwner(
        ?UserId $ownerId,
        ?TaxonomyId $taxId,
        ?string $search,
        bool $includeSystem,
        bool $allOwners = false,
    ): array;

    /**
     * Курсы, назначенные ученику.
     *
     * @return list<Course>
     */
    public function listForStudent(UserId $studentId): array;

    /**
     * Пороги типов уроков из справочника lesson_types:
     * lesson_types.id => next_lesson_threshold (0..1).
     *
     * Нужен AdmissionChecker'у и построению списка уроков с замками.
     *
     * @param list<int> $typeIds
     * @return array<int, float>
     */
    public function typeThresholds(array $typeIds): array;
}
