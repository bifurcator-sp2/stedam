<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Contracts;

use App\Blocks\TestGenerator\DTO\MathProblem;

/**
 * Контракт генератора учебных заданий: генерация MathProblem.
 *
 * Идентификатор генератора — его resolver-путь, вычисляемый из расположения
 * файла в Generators/файлах (см. GeneratorDiscovery / PathResolver).
 * Отдельного метода topic() больше нет: путь и есть идентификатор.
 *
 * generate() обязан построить корректный объект:
 *  - валидировать difficulty в диапазоне [0.0, 1.0];
 *  - question и solution — в разметке KaTeX auto-render (inline \( ... \), display \[ ... \]);
 *  - answer — строка-ответ для проверки учеником.
 */
interface MathProblemGeneratorInterface
{
    /**
     * Сгенерировать экземпляр задания по сложности.
     *
     * @param float $difficulty от 0.0 (просто) до 1.0 (сложно).
     *
     * @throws \InvalidArgumentException если difficulty вне [0.0, 1.0].
     */
    public function generate(float $difficulty): MathProblem;
}