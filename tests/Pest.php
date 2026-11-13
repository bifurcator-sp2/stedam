<?php

declare(strict_types=1);

use App\Domain\Learn\ValueObjects\SnapshotHash;
use Tests\TestCase;

// Unit-тесты домена идут без Laravel — Domain от него не зависит.
// Feature — с TestCase (для HTTP и БД).
uses(TestCase::class)->in('Feature');

// beforeEach() Pest выполняет раньше setUp(), поэтому подготавливаем тестовые
// диски здесь: Storage::fake() не должен натыкаться на каталоги другого uid.
uses()->beforeEach(function (): void {
    TestCase::prepareFakeDisks();
})->in('Feature');

if (!function_exists('domainNow')) {
    /**
     * Фиксированный «сейчас» — 2025-01-01 12:00:00 UTC.
     */
    function domainNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('2025-01-01 12:00:00 UTC');
    }
}

if (!function_exists('domainLater')) {
    /**
     * «Сейчас + 30 минут» — для проверки, что statusDate обновился.
     */
    function domainLater(): DateTimeImmutable
    {
        return new DateTimeImmutable('2025-01-01 12:30:00 UTC');
    }
}

if (!function_exists('domainSnapshot')) {
    function domainSnapshot(): SnapshotHash
    {
        return SnapshotHash::fromContent('lesson-payload');
    }
}

if (!function_exists('domainQuestions')) {
    /**
     * LearnQuestions с заданиями по заданным индексам (по умолчанию — [1]).
     * Индексы заданий должны совпадать с индексами изображений-ответов
     * (answerImages()) — это ключевой инвариант §8.1.
     */
    function domainQuestions(int ...$indices): \App\Domain\Learn\ValueObjects\LearnQuestions
    {
        $indices = $indices === [] ? [1] : $indices;

        return \App\Domain\Learn\ValueObjects\LearnQuestions::fromItems(
            array_map(
                static fn (int $i): \App\Domain\Learn\ValueObjects\QuestionItem =>
                    new \App\Domain\Learn\ValueObjects\QuestionItem(
                        index: $i,
                        question: "Q{$i}",
                        answer: 'A',
                        solution: 'S',
                        topic: 'math.test',
                        difficulty: 0.5,
                        seed: $i,
                    ),
                $indices,
            ),
        );
    }
}

if (!function_exists('answerImages')) {
    /**
     * LearnAnswers с единственным изображением-ответом по индексу.
     */
    function answerImages(int $index = 1): \App\Domain\Learn\ValueObjects\LearnAnswers
    {
        return \App\Domain\Learn\ValueObjects\LearnAnswers::fromImages([
            new \App\Domain\Learn\ValueObjects\AnswerImage($index, "answers/{$index}.webp"),
        ]);
    }
}

if (!function_exists('rawAnswer')) {
    /**
     * Сырой ответ-картинка для action-тестов.
     */
    function rawAnswer(int $index = 1): \App\Application\Learn\DTOs\RawAnswer
    {
        return new \App\Application\Learn\DTOs\RawAnswer(
            $index,
            \Illuminate\Http\UploadedFile::fake()->image("answer_{$index}.png", 200, 200),
        );
    }
}

if (!function_exists('domainZone')) {
    function domainZone(string $name = 'Europe/Moscow'): DateTimeZone
    {
        return new DateTimeZone($name);
    }
}
