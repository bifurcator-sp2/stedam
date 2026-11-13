<?php

declare(strict_types=1);

namespace App\Application\Course\Context;

use App\Domain\Course\ValueObjects\UserId;

/**
 * Кто смотрит на курсы и что ему разрешено (§13.1).
 *
 * Чистая проверка по данным, без походов в БД: роли и связи даёт вызывающая
 * сторона (Infrastructure собирает контекст из запроса и user_relations).
 *
 * Правила видимости (§13.2):
 *  - admin / methodologist / headteacher — всё;
 *  - teacher — свои курсы + системные;
 *  - student — курсы, назначенные ему, + системные (в каталоге);
 *  - parent — курсы, назначенные его детям (наблюдение, без изменения);
 *  - гость (userId = null) — только системные.
 */
final readonly class CourseViewerContext
{
    /**
     * @param list<UserId> $childStudentIds дети наблюдателя (для parent)
     */
    private function __construct(
        private ?UserId $userId,
        private CourseViewerRole $role,
        private array $childStudentIds = [],
    ) {}

    public static function admin(?UserId $userId): self
    {
        return new self($userId, CourseViewerRole::Admin);
    }

    public static function methodologist(?UserId $userId): self
    {
        return new self($userId, CourseViewerRole::Methodologist);
    }

    public static function headteacher(?UserId $userId): self
    {
        return new self($userId, CourseViewerRole::Headteacher);
    }

    public static function teacher(UserId $userId): self
    {
        return new self($userId, CourseViewerRole::Teacher);
    }

    public static function student(UserId $userId): self
    {
        return new self($userId, CourseViewerRole::Student);
    }

    /**
     * @param list<UserId> $childStudentIds
     */
    public static function parent(UserId $userId, array $childStudentIds): self
    {
        return new self($userId, CourseViewerRole::Parent, $childStudentIds);
    }

    public static function guest(): self
    {
        return new self(null, CourseViewerRole::Guest);
    }

    public function userId(): ?UserId
    {
        return $this->userId;
    }

    public function role(): CourseViewerRole
    {
        return $this->role;
    }

    /**
     * @return list<UserId>
     */
    public function childStudentIds(): array
    {
        return $this->childStudentIds;
    }

    /**
     * Видит ли смотрящий всё (без фильтра по владельцу/назначению).
     */
    public function seesEverything(): bool
    {
        return $this->role->seesEverything();
    }

    /**
     * Видит ли смотрящий системные курсы (user_id = null).
     */
    public function seesSystemCourses(): bool
    {
        return true;
    }

    /**
     * Может ли смотрящий РЕДАКТИРОВАТЬ существующий курс.
     *
     * Правило (§13.1): редактирует только тот, кто курс создал.
     * Исключение — системный курс (ownerId = null): у него автора нет,
     * поэтому его правит только admin. Даже admin не правит чужой авторский
     * курс: чтобы править свой, ему нужна роль teacher и курс его авторства.
     */
    public function canEdit(?UserId $courseOwnerId): bool
    {
        if (!$this->role->canEdit()) {
            return false;
        }

        if ($courseOwnerId === null) {
            return $this->role->canEditSystemCourses();
        }

        // Владение — единственный критерий. seesEverything() здесь НЕ даёт
        // прав: «вижу все курсы» ≠ «правлю все курсы».
        return $this->userId?->equals($courseOwnerId) === true;
    }

    /**
     * Может ли смотрящий СОЗДАТЬ курс с данным владельцем.
     *
     * Отличается от canEdit() тем, что у нового курса ещё нет владельца —
     * его назначаем мы. Отсюда два правила:
     *  - обычный курс: создаём ТОЛЬКО себе (ownerId === viewerId);
     *  - системный курс (ownerId = null): только admin.
     */
    public function canCreate(?UserId $ownerId): bool
    {
        if (!$this->role->canCreate()) {
            return false;
        }

        if ($ownerId === null) {
            return $this->role->canCreateSystemCourses();
        }

        return $this->userId?->equals($ownerId) === true;
    }

    /**
     * Может ли смотрящий назначать курс ученикам.
     */
    public function canAssign(): bool
    {
        return $this->role->canAssign();
    }

    /**
     * Смотрит ли смотрящий на курс как ученик (верхняя страница курса —
     * с прохождением, а не с редактором).
     */
    public function isStudentView(): bool
    {
        return $this->role === CourseViewerRole::Student;
    }

    /**
     * Видит ли смотрящий прогресс ученика (свой или ребёнка).
     */
    public function canSeeProgressOf(UserId $studentId): bool
    {
        if ($this->seesEverything()) {
            return true;
        }

        if ($this->role === CourseViewerRole::Student) {
            return $this->userId?->equals($studentId) === true;
        }

        if ($this->role === CourseViewerRole::Parent) {
            foreach ($this->childStudentIds as $childId) {
                if ($childId->equals($studentId)) {
                    return true;
                }
            }
        }

        return false;
    }
}
