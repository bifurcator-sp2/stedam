<?php

declare(strict_types=1);

use App\Domain\Learn\Exceptions\InvalidGrade;
use App\Domain\Learn\ValueObjects\Grade;

it('accepts values in range 0..100', function (float $value) {
    expect((new Grade($value))->value)->toBe($value);
})->with([0.0, 50.5, 99.99, 100.0]);

it('rejects out-of-range values', function (float $invalid) {
    expect(fn () => new Grade($invalid))->toThrow(InvalidGrade::class);
})->with([-0.01, -1.0, 100.01, 1000.0]);

it('creates from int', function () {
    expect(Grade::fromInt(85)->value)->toBe(85.0);
});

it('compares by value with float tolerance', function () {
    expect((new Grade(85.5))->equals(new Grade(85.5)))->toBeTrue()
        ->and((new Grade(85.5))->equals(new Grade(85.50001)))->toBeTrue()
        ->and((new Grade(85.5))->equals(new Grade(85.6)))->toBeFalse();
});

it('formats as string with 2 decimals', function () {
    expect((string) new Grade(85.5))->toBe('85.50')
        ->and((string) new Grade(100.0))->toBe('100.00');
});
