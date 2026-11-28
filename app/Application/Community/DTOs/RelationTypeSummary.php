<?php

declare(strict_types=1);

namespace App\Application\Community\DTOs;

/**
 * Строка списка связей текущего пользователя в контексте участия.
 *
 * Ребро направленное, поэтому пользователь участвует в связи с двух
 * сторон, и каждый элемент описывает ОДНУ его роль в ОДНОЙ связи:
 *   - relation   — код типа ребра (RelationType::value), например `parent`;
 *   - direction  — сторона участия пользователя: `outgoing` (хозяин,
 *                  parent_user_id) или `incoming` (подчинённый, child_user_id);
 *   - role       — роль пользователя на этой стороне (parent/student/teacher/...);
 *   - label      — заголовок раздела профиля «кого я вижу»: я учитель →
 *                  «Ученики» (outgoing), я ученик → «Учителя» (incoming);
 *   - description — пояснение раздела: кого я вижу и кем являюсь для них
 *                  (HTML с <b>/<br> для выделения);
 *   - count      — сколько действующих связей этого типа в этой роли;
 *   - available  — есть ли хотя бы одна связь (count > 0);
 *   - counterpart_role — роль на противоположном конце ребра.
 */
final readonly class RelationTypeSummary
{
    public function __construct(
        public string $relation,
        public string $direction,
        public string $role,
        public string $label,
        public string $description,
        public int $count,
        public bool $available,
        public string $counterpartRole,
    ) {}

    /**
     * @return array{
     *     relation: string,
     *     direction: string,
     *     role: string,
     *     label: string,
     *     description: string,
     *     count: int,
     *     available: bool,
     *     counterpart_role: string
     * }
     */
    public function toArray(): array
    {
        return [
            'relation' => $this->relation,
            'direction' => $this->direction,
            'role' => $this->role,
            'label' => $this->label,
            'description' => $this->description,
            'count' => $this->count,
            'available' => $this->available,
            'counterpart_role' => $this->counterpartRole,
        ];
    }
}
