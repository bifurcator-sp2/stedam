<?php

declare(strict_types=1);

namespace App\Application\Community\Actions;

use App\Application\Community\DTOs\RelationTypeSummary;
use App\Application\Community\Ports\RelationsQueryInterface;
use App\Application\Direction;
use App\Domain\Community\ValueObjects\RelationType;
use App\Domain\Community\ValueObjects\UserId;

/**
 * Связи текущего пользователя в контексте участия — с обеих сторон ребра.
 *
 * GET my-relations → [ RelationTypeSummary, ... ]
 *
 * Связь направленная, поэтому по каждому типу отдаются ДВЕ строки участия:
 *   - outgoing (хозяин ребра, parent_user_id): «я — родитель / учитель»;
 *   - incoming (подчинённый, child_user_id):   «я — ребёнок / ученик».
 * Например, при ребре parent пользователь с одной стороны ребёнка — ребёнок,
 * с другой — родитель; в ответе оба взгляда с честными счётчиками.
 *
 * Возвращаются ВСЕ типы в фиксированном порядке (RelationType::ordered()),
 * у каждой стороны — своя строка; отсутствующие связи — count = 0,
 * available = false: UI рисует полный профиль, а не только заполненные роли.
 *
 * Порт отдаёт только непустые типы; дополнение нулями — здесь, чтобы
 * инфраструктура не знала о полном перечне ролей.
 */
final readonly class GetRelationsTypesAction
{
    public function __construct(
        private RelationsQueryInterface $relations,
    ) {}

    /**
     * @return list<RelationTypeSummary>
     */
    public function execute(UserId $userId): array
    {
        $outgoing = $this->relations->countsByType($userId->value, Direction::Outgoing);
        $incoming = $this->relations->countsByType($userId->value, Direction::Incoming);

        $result = [];

        foreach (RelationType::ordered() as $type) {
            $result[] = $this->summary($type, Direction::Outgoing, $outgoing);
            $result[] = $this->summary($type, Direction::Incoming, $incoming);
        }

        return $result;
    }

    /**
     * @param array<string, int> $counts RelationType::value => count
     */
    private function summary(RelationType $type, Direction $direction, array $counts): RelationTypeSummary
    {
        $count = $counts[$type->value] ?? 0;
        $isOwner = $direction->isOutgoing();

        return new RelationTypeSummary(
            relation: $type->value,
            direction: $direction->value,
            role: $type->roleAt($isOwner),
            label: $type->labelAt($isOwner),
            description: $type->descriptionAt($isOwner, $count),
            count: $count,
            available: $count > 0,
            counterpartRole: $isOwner ? $type->counterpartRole() : $type->ownerRole(),
        );
    }
}
