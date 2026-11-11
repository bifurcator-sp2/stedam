<?php

declare(strict_types=1);

namespace App\Domain\Lessons\Services;

use App\Domain\Lessons\ValueObjects\Complexity;

/**
 * Сложность урока = среднее арифметическое сложностей привязанных
 * к нему заданий.
 *
 * Правила (перенесены из Lesson::recalculateComplexity как есть):
 *   — учитываются ТОЛЬКО задания (pivot), собственные info-блоки
 *     урока в расчёт не входят;
 *   — задания с complexity = null игнорируются (иначе тянули бы
 *     среднее к нулю);
 *   — если валидных значений нет вообще — complexity = null.
 *
 * Вынесено в доменный сервис, а не в сущность Lesson, потому что
 * требует загрузки complexity заданий — а это уже инфраструктурная
 * операция (join pivot + lesson_blocks).
 */
final readonly class ComplexityCalculator
{
    /**
     * @param iterable<float|null> $taskComplexities
     */
    public function averageOf(iterable $taskComplexities): ?Complexity
    {
        return Complexity::averageOf($taskComplexities);
    }
}
