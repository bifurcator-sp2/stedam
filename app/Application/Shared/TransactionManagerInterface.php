<?php

declare(strict_types=1);

namespace App\Application\Shared;

use Closure;
use Throwable;

/**
 * Граница транзакции для операций, которые пишут в несколько мест.
 *
 * Зачем порт, а не DB::transaction() в Action: Application-слой не должен
 * знать про Laravel, иначе домен нельзя поднять без фреймворка (тесты,
 * CLI-инструменты). При этом граница транзакции — часть бизнес-правила,
 * а не деталь HTTP-слоя: она обязана жить рядом с операцией, которую
 * защищает, иначе её забудут в контроллере, джобе или Filament-экшене.
 *
 * ВАЖНО: транзакции НЕ вкладываются друг в друга по замыслу. Если один
 * Action вызывает другой, оба считают себя внешней границей — и получаются
 * вложенные SAVEPOINT'ы. Такого избегаем: либо вызывающий Action делает
 * работу сам, либо вызываемый не открывает транзакцию.
 */
interface TransactionManagerInterface
{
    /**
     * Выполнить в транзакции и вернуть результат.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     *
     * @throws Throwable — всё, что бросила операция, пробрасывается после отката
     */
    public function transactional(Closure $operation): mixed;
}
