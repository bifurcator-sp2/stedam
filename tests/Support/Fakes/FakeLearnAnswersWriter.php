<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\Ports\LearnAnswersWriterInterface;
use App\Domain\Learn\ValueObjects\LearnId;

/**
 * Запоминает вызовы записи сырых ответов; саму запись не эмулирует.
 */
final class FakeLearnAnswersWriter implements LearnAnswersWriterInterface
{
    /** @var list<array{learnId: int, answers: list<array<string, mixed>>}> */
    public array $calls = [];

    public function replaceRawAnswers(LearnId $learnId, array $answers): void
    {
        $this->calls[] = ['learnId' => $learnId->value, 'answers' => $answers];
    }
}
