<?php

declare(strict_types=1);

namespace App\Application\Course\Ports;

use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\UserId;
use Illuminate\Contracts\Pagination\Paginator;

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
     * Листинг для смотрящего (§13.2) с постраничной выборкой.
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
     * Фильтры $taxId и $search:
     *   - $taxId — узел таксономии ВМЕСТЕ со всеми потомками;
     *   - $search — подстрока без учёта регистра по title и description.
     *
     * Пагинация — simple (как у листинга уроков): без COUNT, страница
     * определяется наличием следующей строки. Агрегат отдаётся БЕЗ состава
     * (themes): листингу нужна только шапка.
     *
     * @param bool $allOwners снять фильтр по владельцу полностью
     * @param int  $page      1-based
     * @param int  $perPage   размер страницы
     * @return Paginator
     */
    public function listForOwner(
        ?UserId $ownerId,
        ?TaxonomyId $taxId,
        ?string $search,
        bool $includeSystem,
        bool $allOwners = false,
        int $page = 1,
        int $perPage = 20,
    ): Paginator;

    /**
     * Курсы, назначенные ученику. Пагинация и фильтры — как у listForOwner().
     *
     * @return Paginator
     */
    public function listForStudent(
        UserId $studentId,
        ?TaxonomyId $taxId = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): Paginator;

    /**
     * Пороги типов уроков из справочника lesson_types:
     * lesson_types.code => next_lesson_threshold (0..1).
     *
     * Нужен AdmissionChecker'у и построению списка уроков с замками.
     * Ключ — код типа, потому что урок хранит именно код (lessons.type).
     *
     * @param list<string> $codes
     * @return array<string, float>
     */
    public function typeThresholdsByCode(array $codes): array;
}
