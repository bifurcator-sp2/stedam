<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\ValueObjects\LearnId;

/**
 * Порт записи «сырых» ответов ученика (штрихи с планшета) в learns.answers.
 *
 * Формат — фронтенд-структура блоков: [{version, strokes: [...]}, ...].
 * Application не знает, какая колонка/тип хранит это в БД;
 * физическая запись — в Infrastructure.
 */
interface LearnAnswersWriterInterface
{
    /**
     * Полная замена learns.answers указанным прохождениям.
     *
     * $answers передаётся как есть, без интерпретации структуры.
     *
     * @param list<array<string, mixed>> $answers
     */
    public function replaceRawAnswers(LearnId $learnId, array $answers): void;
}
