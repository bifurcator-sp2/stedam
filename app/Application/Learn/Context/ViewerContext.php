<?php

declare(strict_types=1);

namespace App\Application\Learn\Context;

/**
 * Контекст просмотра прохождений: кто смотрит и что ему позволено.
 *
 * Application-понятие (не Domain): домену Learn не важно, кто смотрит.
 * Строится ContextResolverInterface из ролей пользователя и графа владения.
 *
 * Неизменяемый value object.
 *
 *   roles             — роли, релевантные Learn (объединение всех ролей user).
 *   visibleUserIds    — множество user_id, чьи прохождения видны этому смотрящему
 *                       (сюда всегда входит сам смотрящий). Пустое быть не может,
 *                       но каждый userId уникален.
 *   can*              — права на действия с прохождениями в рамках своего scope.
 *                       Родитель — read-only (все can* = false).
 *
 * Архивация НЕ является пользовательским действием: устаревшие прохождения
 * архивируются системой по расписанию (крон). Поэтому capability архивации
 * в контексте отсутствует.
 */
final readonly class ViewerContext
{
    /** @var list<int> */
    private array $visibleUserIds;

    /**
     * @param list<ViewerRole> $roles
     * @param iterable<int> $visibleUserIds
     */
    public function __construct(
        public int $viewerId,
        public array $roles,
        iterable $visibleUserIds,
        public bool $canGrade,
        public bool $canAssign,
        public bool $canSchedule,
        public bool $canCancel,
        public bool $canStart,
        public bool $canSave,
    ) {
        $unique = [];
        foreach ($visibleUserIds as $id) {
            $unique[(int) $id] = true;
        }
        $ids = array_keys($unique);
        sort($ids);
        $this->visibleUserIds = $ids;
    }

    /**
     * @return list<int>
     */
    public function visibleUserIds(): array
    {
        return $this->visibleUserIds;
    }

    public function canSeeUser(int $userId): bool
    {
        return in_array($userId, $this->visibleUserIds, true);
    }

    public function hasRole(ViewerRole $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isReadOnly(): bool
    {
        return !$this->canGrade && !$this->canAssign && !$this->canSchedule
            && !$this->canCancel && !$this->canStart && !$this->canSave;
    }

    /**
     * Контекст «только для чтения» с заданным scope.
     *
     * @param list<ViewerRole> $roles
     * @param iterable<int> $visibleUserIds
     */
    public static function readOnly(int $viewerId, array $roles, iterable $visibleUserIds): self
    {
        return new self(
            viewerId: $viewerId,
            roles: $roles,
            visibleUserIds: $visibleUserIds,
            canGrade: false,
            canAssign: false,
            canSchedule: false,
            canCancel: false,
            canStart: false,
            canSave: false,
        );
    }
}
