<?php

declare(strict_types=1);

use App\Domain\Learn\Exceptions\InvalidId;
use App\Domain\Learn\ValueObjects\UserId;

it('accepts positive id', function (int $value) {
    expect((new UserId($value))->value)->toBe($value);
})->with([1, 42, 1_000_000]);

it('rejects non-positive id', function (int $invalid) {
    expect(fn () => new UserId($invalid))->toThrow(InvalidId::class);
})->with([0, -1, -100]);

it('compares by value', function () {
    expect((new UserId(5))->equals(new UserId(5)))->toBeTrue()
        ->and((new UserId(5))->equals(new UserId(6)))->toBeFalse();
});
