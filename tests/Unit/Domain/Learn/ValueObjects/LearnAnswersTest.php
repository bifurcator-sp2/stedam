<?php

declare(strict_types=1);

use App\Domain\Learn\Exceptions\InvalidAnswerImage;
use App\Domain\Learn\ValueObjects\AnswerImage;
use App\Domain\Learn\ValueObjects\LearnAnswers;

it('holds answer images', function () {
    $answers = LearnAnswers::fromImages([
        new AnswerImage(2, 'answers/001/1/2.webp'),
        new AnswerImage(1, 'answers/001/1/1.webp'),
    ]);

    // Упорядочено по index возрастанию.
    expect($answers->toArray())->toBe([
        ['index' => 1, 'file' => 'answers/001/1/1.webp'],
        ['index' => 2, 'file' => 'answers/001/1/2.webp'],
    ])->and($answers->isEmpty())->toBeFalse();
});

it('can be empty', function () {
    expect(LearnAnswers::empty()->isEmpty())->toBeTrue()
        ->and(LearnAnswers::fromArray([]))->isEmpty()->toBeTrue();
});

it('restores from persisted array', function () {
    $answers = LearnAnswers::fromArray([
        ['index' => 5, 'file' => 'answers/x.webp'],
    ]);

    expect($answers->toArray())->toBe([
        ['index' => 5, 'file' => 'answers/x.webp'],
    ]);
});

it('treats foreign/legacy payload as empty', function () {
    // Старые данные произвольной структуры — «нет ответов».
    $answers = LearnAnswers::fromArray(['q1' => 'a1']);

    expect($answers->isEmpty())->toBeTrue();
});

it('skips entries with non-positive or non-numeric index (spec §7.2)', function () {
    $answers = LearnAnswers::fromArray([
        ['index' => 0, 'file' => 'answers/a.webp'],      // index < 1 → пропуск
        ['index' => 'abc', 'file' => 'answers/b.webp'],  // нечисловой → (int)=0 → пропуск
        ['index' => 2, 'file' => 'answers/c.webp'],      // валидный
    ]);

    expect($answers->toArray())->toBe([
        ['index' => 2, 'file' => 'answers/c.webp'],
    ]);
});

it('rejects duplicate index', function () {
    expect(fn () => LearnAnswers::fromImages([
        new AnswerImage(1, 'a.webp'),
        new AnswerImage(1, 'b.webp'),
    ]))->toThrow(InvalidAnswerImage::class);
});

it('does not expose mutable internal storage', function () {
    $answers = LearnAnswers::fromImages([new AnswerImage(1, 'a.webp')]);

    // $items — private: снаружи доступ только через images()/toArray().
    expect(property_exists($answers, 'items'))->toBeTrue()
        ->and((new ReflectionProperty(LearnAnswers::class, 'items'))->isPrivate())->toBeTrue();
});

it('compares by value', function () {
    $a = LearnAnswers::fromImages([new AnswerImage(1, 'a.webp')]);
    $b = LearnAnswers::fromImages([new AnswerImage(1, 'a.webp')]);
    $c = LearnAnswers::fromImages([new AnswerImage(1, 'b.webp')]);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});
