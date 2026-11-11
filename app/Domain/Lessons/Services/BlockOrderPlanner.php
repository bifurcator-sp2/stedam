<?php

declare(strict_types=1);

namespace App\Domain\Lessons\Services;

/**
 * Планировщик порядка блоков урока.
 *
 * Задача: «вставить блок на позицию N, сдвинув всё, что было на N и правее».
 *
 * Порядок урока — сквозной для двух наборов: собственных блоков
 * (lesson_blocks."order") и привязанных заданий
 * (lesson_lesson_block_as_test."order"). Поэтому сдвиг затрагивает оба.
 *
 * Проблема PostgreSQL: при UPDATE с UNIQUE/BTREE-индексом по order
 * построчный сдвиг может временно создать дубликаты. В текущем коде
 * (LessonController::storeBlock) это решается «безопасным сдвигом»:
 *   1. сдвинуть всё на +1000000 (попадает в заведомо свободную зону);
 *   2. вставить новый элемент на N;
 *   3. вернуть сдвинутое назад, но уже на +1.
 *
 * Этот сервис описывает ТУ ЖЕ механику, но без SQL: он возвращает
 * «план» — какие диапазоны и на сколько сдвигать. Инфраструктура
 * исполняет план одним UPDATE.
 *
 * Смысл выделения: гарантировать, что правило порядка единообразно
 * работает и для HTTP-контроллера, и для любых будущих потребителей
 * (в т.ч. сеялок).
 */
final readonly class BlockOrderPlanner
{
    /**
     * Magic offset. Должен быть заведомо больше любого реального числа
     * блоков в уроке и не участвовать в нормальном диапазоне.
     */
    public const SAFE_OFFSET = 1_000_000;

    /**
     * Вставить элемент на позицию $targetOrder, сдвинув всё, что >= неё.
     *
     * Возвращает инструкцию для хранилища:
     *   — $shiftRange    : [from, +INF) сдвигается на +1
     *   — $maxOrderKnown : максимальный известный order в уроке (для
     *                      выбора «append в конец»)
     *
     * $targetOrder === null означает «в конец по умолчанию».
     */
    public function planInsert(?int $targetOrder, int $maxOrderKnown): InsertPlan
    {
        $order = $targetOrder ?? $maxOrderKnown + 1;

        if ($order < 0) {
            throw new \InvalidArgumentException("Target order must be >= 0, got {$order}");
        }

        return new InsertPlan($order);
    }
}
