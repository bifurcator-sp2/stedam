<?php

declare(strict_types=1);

namespace App\Domain\Community\ValueObjects;

use InvalidArgumentException;

/**
 * Связь пользователя в одной роли и одном направлении.
 *
 * Например: «у меня 3 ученика», «у меня 2 учителя». Строка результата
 * GetRelationsTypes; список таких строк — статусы связей текущего
 * пользователя, что реально есть в графе.
 *
 * count >= 0: тип без связей тоже попадает в список (с count = 0), чтобы
 * UI показывал полную матрицу ролей, а не только заполненные строки.
 */
final readonly class RelationTypeStat
{
    public function __construct(
        public RelationType $type,
        public int $count,
    ) {
        if ($count < 0) {
            throw new InvalidArgumentException('Relation count cannot be negative');
        }
    }
}
