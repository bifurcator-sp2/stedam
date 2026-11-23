<?php

declare(strict_types=1);

namespace App\Application\Lessons\Enums;

/**
 * Срез списка «Актуальные уроки» (GET /lessons/actual?filter=...).
 *
 *   — Unassigned — уроки без learns текущего пользователя
 *     (сортировка: lessons.created_at DESC);
 *   — Assigned   — learns текущего пользователя в статусе created
 *     (сортировка: learns.created_at DESC);
 *   — Continue   — learns текущего пользователя в статусе in_progress
 *     (сортировка: последняя смена статуса в learn_statuses DESC);
 *   — Grades     — learns с оценками (learn_grades) текущего пользователя
 *     в любом статусе (сортировка: learn_grades.updated_at DESC).
 */
enum ActualLessonsScope: string
{
    case Unassigned = 'unassigned';
    case Assigned = 'assigned';
    case Continue = 'continue';
    case Grades = 'grades';
}
