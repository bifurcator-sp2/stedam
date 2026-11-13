<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared;

use App\Application\Shared\TransactionManagerInterface;
use Closure;
use Illuminate\Database\DatabaseManager;

/**
 * Транзакции поверх Laravel DatabaseManager.
 *
 * Намеренно не используется фасад DB: явная зависимость от DatabaseManager
 * позволяет подменить соединение в тестах и не тянет фасад со статическим
 * состоянием внутрь Application-слоя.
 *
 * Ограничение: покрывает только ОДНО соединение. Если Learn когда-нибудь
 * уедет на отдельную БД, AssignCourseAction перестанет быть атомарным —
 * назначение сохранится, а прохождения нет. Пока обе таблицы в одной БД
 * (PostgreSQL по умолчанию), это работает.
 */
final readonly class EloquentTransactionManager implements TransactionManagerInterface
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function transactional(Closure $operation): mixed
    {
        return $this->db->connection()->transaction($operation);
    }
}
