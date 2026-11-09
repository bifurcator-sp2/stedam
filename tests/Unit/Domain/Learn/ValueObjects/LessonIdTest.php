<?php

declare(strict_types=1);

use App\Domain\Learn\Exceptions\InvalidId;
use App\Domain\Learn\ValueObjects\LessonId;

it('accepts positive id', function (int $value) {
    expect((new LessonId($value))->value)->toBe($value);
})->with([1, 42, 1_000_000]);

it('rejects non-positive id', function (int $invalid) {
    expect(fn () => new LessonId($invalid))->toThrow(InvalidId::class);
})->with([0, -1, -100]);

it('compares by value', function () {
    expect((new LessonId(5))->equals(new LessonId(5)))->toBeTrue()
        ->and((new LessonId(5))->equals(new LessonId(6)))->toBeFalse();
});
