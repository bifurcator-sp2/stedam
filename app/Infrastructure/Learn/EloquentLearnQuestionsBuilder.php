<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\DTOs\LearnSeed;
use App\Application\Learn\Exceptions\LessonNotFound;
use App\Application\Learn\Ports\LearnQuestionsBuilderInterface;
use App\Application\Learn\Ports\ProcedureResolverPathInterface;
use App\Domain\Blocks\TestGenerator\Random\Seed;
use App\Domain\Blocks\TestGenerator\Ports\ProblemGeneratorInterface;
use App\Domain\Learn\ValueObjects\LearnQuestions;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\QuestionItem;
use App\Models\Lesson;
use App\Models\LessonBlock;
use Illuminate\Support\Facades\Log;

/**
 * Генерация задач прохождения (материализация случайности для ученика).
 *
 * Инварианты (спека §7.8, §8.1):
 *   — в набор попадают ТОЛЬКО proc/test блоки;
 *   — index = позиция блока в allBlocksOrdered() + 1
 *     (та же нумерация, что и у AnswerImage::index);
 *   — сид = Seed::forTask(userId, learnId, blockIndex) — детерминизм по
 *     прохождению, повторный старт того же learnId даёт ту же серию.
 */
final readonly class EloquentLearnQuestionsBuilder implements LearnQuestionsBuilderInterface
{
    /**
     * Сколько раз пробуем перегенерировать при коллизии (два задания одной
     * серии вышли одинаковыми). См. Seed::withAttempt().
     */
    private const COLLISION_ATTEMPTS = 3;

    public function __construct(
        private ProcedureResolverPathInterface $resolverPaths,
        private ProblemGeneratorInterface $generator,
    ) {}

    public function buildFor(LearnSeed $seed, LessonId $lessonId): LearnQuestions
    {
        $lesson = Lesson::query()
            ->with(['blocks.block', 'lessonBlockAsTests.block', 'taxonomies:id'])
            ->find($lessonId->value);

        if ($lesson === null) {
            throw new LessonNotFound("Lesson {$lessonId->value} not found");
        }

        $resolverPath = $this->resolverPaths->resolveForLesson($lessonId);
        if ($resolverPath === null) {
            // Урок без процедурных блоков — генерировать нечего.
            return LearnQuestions::empty();
        }

        $items         = [];
        $seenQuestions = [];

        foreach ($lesson->allBlocksOrdered() as $position => $block) {
            /** @var LessonBlock $block */
            if (!(bool) $block->is_proc) {
                continue; // статический блок — запечён в снапшоте
            }

            // Нумерация 1-based, совпадает с AnswerImage::index.
            $index      = $position + 1;
            $difficulty = (float) ($block->complexity ?? 0.5);

            $items[] = $this->generateItem(
                resolverPath: $resolverPath,
                difficulty:   $difficulty,
                index:        $index,
                seed:         $seed,
                position:     $position,
                seen:         $seenQuestions,
            );
        }

        return LearnQuestions::fromItems($items);
    }

    /**
     * @param array<string, true> $seen мутабельный набор уже выданных условий
     *                                   в этой серии — для дедупликации.
     */
    private function generateItem(
        string $resolverPath,
        float $difficulty,
        int $index,
        LearnSeed $seed,
        int $position,
        array &$seen,
    ): QuestionItem {
        $baseSeed = Seed::forTask($seed->userId->value, $seed->learnId->value, $position);

        $attempt = 0;
        do {
            $attemptSeed = $attempt === 0 ? $baseSeed : Seed::withAttempt($baseSeed, $attempt);
            $problem     = $this->generator->generate(
                $resolverPath,
                $difficulty,
                Seed::fromInt($attemptSeed),
            );

            if (!isset($seen[$problem->question])) {
                break;
            }

            $attempt++;
        } while ($attempt <= self::COLLISION_ATTEMPTS);

        if (isset($seen[$problem->question])) {
            // После всех попыток всё равно дубль — оставляем как есть и логируем.
            Log::warning('LearnQuestions: could not avoid duplicate problem', [
                'resolver_path' => $resolverPath,
                'difficulty'    => $difficulty,
                'learn_id'      => $seed->learnId->value,
                'user_id'       => $seed->userId->value,
                'index'         => $index,
            ]);
        }

        $seen[$problem->question] = true;

        return new QuestionItem(
            index:      $index,
            question:   $problem->question,
            answer:     $problem->answer,
            solution:   $problem->solution,
            topic:      $problem->topic,
            difficulty: $problem->difficulty,
            seed:       $attemptSeed,
        );
    }
}
