<?php

declare(strict_types=1);

namespace App\Application\Course\Context;

/**
 * Роль смотрящего в контексте курсов (§13.1).
 *
 * Матрица прав:
 *
 * | Роль          | Видит            | Редактирует      | Создаёт системный | Назначает |
 * |---------------|------------------|------------------|-------------------|-----------|
 * | Admin         | всё              | свои + системные | да                | да        |
 * | Methodologist | всё              | свои             | нет               | да        |
 * | Headteacher   | всё              | свои             | нет               | да        |
 * | Teacher       | свои + системные | свои             | нет               | да        |
 * | Student       | назначенные      | —                | нет               | —         |
 * | Parent        | детям            | —                | нет               | —         |
 * | Guest         | системные        | —                | нет               | —         |
 *
 * Ключевое: «вижу все курсы» ≠ «правлю все курсы». Редактирование всегда
 * идёт только по владению (см. CourseViewerContext::canEdit). У системных
 * курсов автора нет, поэтому единственный, кто их правит и создаёт — admin.
 */
enum CourseViewerRole: string
{
    case Admin = 'admin';
    case Methodologist = 'methodologist';
    case Headteacher = 'headteacher';
    case Teacher = 'teacher';
    case Student = 'student';
    case Parent = 'parent';
    case Guest = 'guest';

    public function seesEverything(): bool
    {
        return match ($this) {
            self::Admin, self::Methodologist, self::Headteacher => true,
            default => false,
        };
    }

    /**
     * Может ли роль вообще редактировать курсы (шапку и состав).
     * Конкретный курс решается по владению в CourseViewerContext::canEdit().
     */
    public function canEdit(): bool
    {
        return match ($this) {
            self::Admin,
            self::Methodologist,
            self::Headteacher,
            self::Teacher => true,
            default => false,
        };
    }

    /**
     * Может ли роль вообще создавать курсы.
     */
    public function canCreate(): bool
    {
        return $this->canEdit();
    }

    /**
     * Может ли роль РЕДАКТИРОВАТЬ системный курс (user_id = null).
     *
     * Только admin: у системных курсов нет автора, поэтому «право владения»
     * не работает, и единственное исключение из правила — админ.
     */
    public function canEditSystemCourses(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Может ли роль СОЗДАТЬ системный курс (user_id = null).
     * Только admin — методист системные курсы не создаёт (требование заказчика).
     */
    public function canCreateSystemCourses(): bool
    {
        return $this === self::Admin;
    }

    public function canAssign(): bool
    {
        return match ($this) {
            self::Admin,
            self::Methodologist,
            self::Headteacher,
            self::Teacher => true,
            default => false,
        };
    }
}
