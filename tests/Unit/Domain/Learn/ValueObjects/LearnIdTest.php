<?php

declare(strict_types=1);

use App\Domain\Learn\Exceptions\InvalidId;
use App\Domain\Learn\ValueObjects\LearnId;

it('accepts positive id', function (int $value) {
    expect((new LearnId($value))->value)->toBe($value);
})->with([1, 42, 1_000_000]);

it('rejects non-positive id', function (int $invalid) {
    expect(fn () => new LearnId($invalid))->toThrow(InvalidId::class);
})->with([0, -1, -100]);

it('compares by value', function () {
    expect((new LearnId(5))->equals(new LearnId(5)))->toBeTrue()
        ->and((new LearnId(5))->equals(new LearnId(6)))->toBeFalse();
});
