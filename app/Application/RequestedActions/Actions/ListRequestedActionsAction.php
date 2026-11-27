<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Application\RequestedActions\DTOs\RequestedActionListFilter;
use App\Application\RequestedActions\Ports\RequestedActionsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\ValueObjects\UserId;

/**
 * Чтение «моих заявок» по «сырым» параметрам запроса.
 *
 * Ответственность Action:
 *   — зафиксировать автора из auth() (query-параметры автор не переопределяют);
 *   — нормализовать `search`: сначала попытка привести к int — при успехе
 *     поиск идёт по id заявки и по id получателя из payload.consumers;
 *   — привести `statuses` / `handlers` к спискам строк;
 *   — делегировать в read-порт.
 *
 * SQL/PostgreSQL-детали сюда не проникают — они в реализации порта.
 */
final readonly class ListRequestedActionsAction
{
    /** Минимальная длина подстроки для текстового поиска (вхождения). */
    private const MIN_SEARCH_CHARS = 3;

    public function __construct(
        private RequestedActionsQueryInterface $actions,
    ) {}

    /**
     * @param array<string, mixed> $input query-параметры запроса
     * @return PaginatedResult<\App\Models\RequestedAction>
     */
    public function paginate(UserId $initiator, array $input, int $page, int $perPage): PaginatedResult
    {
        return $this->actions->paginate(self::buildFilter($initiator, $input), $page, $perPage);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function buildFilter(UserId $initiator, array $input): RequestedActionListFilter
    {
        [$search, $searchById] = self::buildSearch($input['search'] ?? null);

        return new RequestedActionListFilter(
            initiatorId: $initiator->value,
            search: $search,
            searchById: $searchById,
            statuses: self::buildStatuses($input['statuses'] ?? null),
            aliases: self::buildHandlers($input['handlers'] ?? null),
        );
    }

    /**
     * Поиск: пустая строка или строка короче 3 символов — «поиска нет».
     * «12» → поиск по id заявки и по id получателя из payload.consumers;
     * «ivan» → вхождения по email/ФИО получателей.
     *
     * @return array{0: ?string, 1: bool}
     */
    private static function buildSearch(mixed $raw): array
    {
        if (is_int($raw)) {
            return [(string) max(0, $raw), true];
        }

        if (!is_string($raw)) {
            return [null, false];
        }

        $search = trim($raw);
        if ($search === '') {
            return [null, false];
        }

        // Сначала — попытка целиком привести ввод к int (успех → поиск по id).
        if (filter_var($search, FILTER_VALIDATE_INT) !== false) {
            return [(string) (int) $search, true];
        }

        // Короткий ввод не ищем: подстрока от 3 символов.
        if (mb_strlen($search) < self::MIN_SEARCH_CHARS) {
            return [null, false];
        }

        return [$search, false];
    }

    /**
     * statuses=['pending','done'] (или строка "pending,done") → значения enum.
     * Неизвестные значения отбрасываются.
     *
     * @return list<string>
     */
    private static function buildStatuses(mixed $raw): array
    {
        return array_values(array_filter(
            self::stringList($raw),
            static fn (string $status): bool => RequestedActionStatus::tryFrom($status) !== null,
        ));
    }

    /**
     * handlers=['StudentToTeacher'] (или строка) → alias'ы обработчиков.
     *
     * @return list<string>
     */
    private static function buildHandlers(mixed $raw): array
    {
        return self::stringList($raw);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($value) => is_string($value) ? trim($value) : '',
            $raw,
        ))));
    }
}
