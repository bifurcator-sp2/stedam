<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Context;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\Context\RelationType;
use App\Application\Learn\Context\ViewerContext;
use App\Application\Learn\Context\ViewerRole;
use App\Domain\Learn\ValueObjects\UserId;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Реализация ContextResolverInterface поверх Spatie-ролей и графа user_relations.
 *
 * Правила scope (см. спеку):
 *   student        — только сам.
 *   teacher        — его ученики (teaches).
 *   methodologist  — ученики его учителей (supervises → teaches).
 *   headteacher    — ученики учителей его методистов (heads → supervises → teaches).
 *   parent         — его дети (parents), read-only.
 *
 * Смотрящий всегда видит себя. Мультироль → объединение scope всех ролей.
 *
 * "Действующая связь": active_to IS NULL или active_to > now().
 * Промежуточные уровни (учителя/методисты) в видимые id НЕ попадают —
 * only конечные ученики (у них бывают прохождения).
 */
final class EloquentContextResolver implements ContextResolverInterface
{
    public function resolve(UserId $userId): ViewerContext
    {
        $user = User::query()->find($userId->value);

        if ($user === null) {
            // Нет пользователя — пустой read-only контекст на себя.
            return ViewerContext::readOnly($userId->value, [], [$userId->value]);
        }

        /** @var array<string, true> $userRoles */
        $userRoles = array_fill_keys($user->getRoleNames()->all(), true);

        $roles = [];
        foreach (ViewerRole::cases() as $role) {
            if (isset($userRoles[$role->value])) {
                $roles[] = $role;
            }
        }

        $visible = [$userId->value => true];

        // student: видит себя — уже добавлено.

        // teacher: его ученики.
        if (isset($userRoles[ViewerRole::Teacher->value])) {
            foreach ($this->directChildren($userId->value, RelationType::Teaches) as $id) {
                $visible[$id] = true;
            }
        }

        // parent: его дети (read-only).
        if (isset($userRoles[ViewerRole::Parent->value])) {
            foreach ($this->directChildren($userId->value, RelationType::Parents) as $id) {
                $visible[$id] = true;
            }
        }

        // methodologist: ученики его учителей (2 хопа).
        if (isset($userRoles[ViewerRole::Methodologist->value])) {
            $teacherIds = $this->directChildren($userId->value, RelationType::Supervises);
            foreach ($teacherIds as $teacherId) {
                foreach ($this->directChildren($teacherId, RelationType::Teaches) as $id) {
                    $visible[$id] = true;
                }
            }
        }

        // headteacher: ученики учителей его методистов (3 хопа).
        if (isset($userRoles[ViewerRole::Headteacher->value])) {
            $methodologistIds = $this->directChildren($userId->value, RelationType::Heads);
            foreach ($methodologistIds as $methodologistId) {
                $teacherIds = $this->directChildren($methodologistId, RelationType::Supervises);
                foreach ($teacherIds as $teacherId) {
                    foreach ($this->directChildren($teacherId, RelationType::Teaches) as $id) {
                        $visible[$id] = true;
                    }
                }
            }
        }

        return new ViewerContext(
            viewerId: $userId->value,
            roles: $roles,
            visibleUserIds: array_keys($visible),
            // Ученик: стартовать, сохранять, завершать — свои прохождения.
            canStart: isset($userRoles[ViewerRole::Student->value]),
            canSave: isset($userRoles[ViewerRole::Student->value]),
            // Учитель — оценка/назначение/планирование/отмена своих учеников.
            // archive — системное действие (крон), в контексте отсутствует.
            canGrade: isset($userRoles[ViewerRole::Teacher->value]),
            canAssign: isset($userRoles[ViewerRole::Teacher->value]),
            canSchedule: isset($userRoles[ViewerRole::Teacher->value]),
            canCancel: isset($userRoles[ViewerRole::Teacher->value]),
            // parent / methodologist / headteacher — read-only по спеке.
        );
    }

    /**
     * Прямые «подчинённые» по ребру заданного типа (действующие связи).
     *
     * @return list<int>
     */
    private function directChildren(int $parentId, RelationType $relation): array
    {
        return DB::table('user_relations')
            ->where('parent_user_id', $parentId)
            ->where('relation', $relation->value)
            ->where(function ($q): void {
                $q->whereNull('active_to')
                    ->orWhere('active_to', '>', now());
            })
            ->pluck('child_user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
