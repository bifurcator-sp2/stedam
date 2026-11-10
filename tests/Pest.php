<?php

declare(strict_types=1);

use App\Domain\Learn\ValueObjects\SnapshotHash;
use Tests\TestCase;

// Unit-тесты домена идут без Laravel — Domain от него не зависит.
// Feature — с TestCase (для HTTP и БД).
uses(TestCase::class)->in('Feature');

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
