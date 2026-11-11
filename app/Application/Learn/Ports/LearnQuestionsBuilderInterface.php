<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Application\Learn\DTOs\LearnSeed;
use App\Domain\Learn\ValueObjects\LearnQuestions;
use App\Domain\Learn\ValueObjects\LessonId;

/**
 * Генератор заданий для конкретного прохождения.
 *
 * Возвращает LearnQuestions, где index каждого QuestionItem равен
 * позиции блока в allBlocksOrdered() + 1 — та же семантика, что и
 * у AnswerImage::index (см. спеку §8.1).
 *
 * В набор попадают ТОЛЬКО генеративные (proc/test) блоки:
 * статические блоки запечены в снапшоте урока и в learn не дублируются.
 *
 * Реализация (Infrastructure) сидирует генератор тройкой
 * (userId, learnId, blockIndex) — детерминизм по прохождению.
 */
interface LearnQuestionsBuilderInterface
{
    public function buildFor(LearnSeed $seed, LessonId $lessonId): LearnQuestions;
}
